"""Rebuild a complete HDFC statement CSV from the bank's own PDF.

pdftotext -layout collapses the vertical position, and an HDFC narration wraps
over up to three lines, so a run of narration-only lines between two rows cannot
be split reliably. -bbox-layout keeps the y coordinate, which is what actually
separates them: every wrapped narration line sits nearer its own row's amount
line than to the neighbouring row's.

Nothing here is trusted on faith. verify() re-derives the row count, the debit and
credit counts, both totals and the closing balance, and walks the balance column;
all six must match the STATEMENT SUMMARY the bank printed on the last page.
"""
import re
import subprocess
import sys
import csv
from xml.etree import ElementTree as ET

NS = '{http://www.w3.org/1999/xhtml}'
DATE = re.compile(r'^\d{2}/\d{2}/\d{4}$')
# 1,234.56 | 0.00 | -695.56  (HDFC really does print negative amounts)
NUM = re.compile(r'^-?[\d,]+\.\d{2}$')


def lines_with_y(pdf, ytol=3.0):
    """pdftotext emits one <line> per table CELL, not per visual row, so the
    date, the narration and the amounts of a single row arrive as separate
    elements. Rebuild visual rows by clustering words on their y coordinate."""
    xml = subprocess.run(['pdftotext', '-bbox-layout', pdf, '-'],
                         capture_output=True, check=True).stdout
    root = ET.fromstring(xml)
    for page in root.iter(f'{NS}page'):
        words = [(float(w.get('yMin')), float(w.get('xMin')), (w.text or '').strip())
                 for w in page.iter(f'{NS}word') if (w.text or '').strip()]
        words.sort()
        rows, cur, cur_y = [], [], None
        for y, x, t in words:
            if cur_y is None or abs(y - cur_y) <= ytol:
                cur.append((x, t))
                cur_y = y if cur_y is None else cur_y
            else:
                rows.append((cur_y, sorted(cur)))
                cur, cur_y = [(x, t)], y
        if cur:
            rows.append((cur_y, sorted(cur)))
        yield [{'y': y, 'words': ws, 'text': ' '.join(t for _, t in ws)}
               for y, ws in rows]


def parse_page(lines):
    """Return this page's records. An 'amount line' starts with a date and ends
    with withdrawal / deposit / closing balance."""
    anchors = []
    for i, ln in enumerate(lines):
        w = [t for _, t in ln['words']]
        if len(w) >= 4 and DATE.match(w[0]) and all(NUM.match(x) for x in w[-3:]):
            anchors.append(i)

    records = []
    for k, i in enumerate(anchors):
        ln = lines[i]
        w = [t for _, t in ln['words']]
        withdrawal, deposit, balance = w[-3], w[-2], w[-1]

        # Between the date and the amounts sit (optionally) narration text, the
        # cheque/reference number and the value date -- in that order. Walk in
        # from the right: value date, then a ref only if it is not narration.
        mid = w[1:-3]
        value_date = None
        ref = ''
        if mid and DATE.match(mid[-1]):
            value_date = mid[-1]
            mid = mid[:-1]
        # A reference is a single bare token sitting in its own column, far right
        # of the narration. Use x position: refs start beyond the narration column.
        ref_x = 300.0
        xs = {t: x for x, t in ln['words']}
        if mid and xs.get(mid[-1], 0) > ref_x:
            ref = mid[-1]
            mid = mid[:-1]

        records.append({
            'y': ln['y'], 'date': w[0], 'value_date': value_date or w[0],
            'ref': ref, 'mid': ' '.join(mid),
            'withdrawal': withdrawal, 'deposit': deposit, 'balance': balance,
            'narr': [],
        })

    # Assign every narration-only line to the nearest amount line.
    if not records:
        return []
    for i, ln in enumerate(lines):
        if i in anchors:
            continue
        w = [t for _, t in ln['words']]
        if not w or DATE.match(w[0]) or any(NUM.match(x) for x in w):
            continue                      # header, footer, page furniture
        x0 = ln['words'][0][0]
        if not (60 < x0 < 300):           # narration column only
            continue
        nearest = min(records, key=lambda r: abs(r['y'] - ln['y']))
        if abs(nearest['y'] - ln['y']) > 30:
            continue
        nearest['narr'].append((ln['y'], ln['text']))

    rows = []
    for r in records:
        before = [t for y, t in sorted(r['narr']) if y < r['y']]
        after = [t for y, t in sorted(r['narr']) if y > r['y']]
        narration = ' '.join([*before, r['mid'], *after]).strip()
        narration = re.sub(r'\s+', ' ', narration)
        rows.append([r['date'], narration, r['ref'], r['value_date'],
                     r['withdrawal'], r['deposit'], r['balance']])
    return rows


def paise(s):
    return round(float(s.replace(',', '')) * 100)


def verify(rows, opening, dr_count, cr_count, debits, credits, closing):
    ok = True
    def chk(label, got, want):
        nonlocal ok
        good = got == want
        ok &= good
        print(f'  {"ok  " if good else "FAIL"} {label:<26} {got!r:>18} vs {want!r}')

    chk('row count', len(rows), dr_count + cr_count)
    dr = [r for r in rows if paise(r[4]) != 0]
    cr = [r for r in rows if paise(r[5]) != 0]
    chk('debit rows', len(dr), dr_count)
    chk('credit rows', len(cr), cr_count)
    chk('total debits', sum(paise(r[4]) for r in rows), paise(debits))
    chk('total credits', sum(paise(r[5]) for r in rows), paise(credits))
    chk('closing balance', paise(rows[-1][6]), paise(closing))

    bal = paise(opening)
    breaks = 0
    for r in rows:
        bal += paise(r[5]) - paise(r[4])
        if bal != paise(r[6]):
            breaks += 1
            bal = paise(r[6])
    chk('balance continuity breaks', breaks, 0)
    return ok


if __name__ == '__main__':
    pdf, out, opening, drc, crc, deb, cre, clo = sys.argv[1:9]
    rows = []
    for page in lines_with_y(pdf):
        rows.extend(parse_page(page))
    print(f'{pdf.split("/")[-1]}: extracted {len(rows)} rows')
    good = verify(rows, opening, int(drc), int(crc), deb, cre, clo)
    if not good:
        print('*** verification FAILED, not writing ***')
        sys.exit(1)
    with open(out, 'w', newline='') as f:
        # csv.writer defaults to CRLF; the bank's own export uses LF, and the
        # layout fingerprint is taken over the raw header bytes.
        w = csv.writer(f, lineterminator='\n')
        w.writerow(['Date', 'Narration', 'Chq./Ref.No.', 'Value Date',
                    'Withdrawal Amount', 'Deposit Amount', 'Closing Balance'])
        w.writerows(rows)
    print(f'wrote {out}')

"""Read cell values only; never execute spreadsheet formulas or macros."""
import csv
import datetime
import json
import pathlib
import sys
import zipfile

path = pathlib.Path(sys.argv[1])
extension = sys.argv[2].lower()
if path.stat().st_size > 20 * 1024 * 1024:
    raise ValueError('文件超过20MiB')
sheets = []
if extension == 'xls':
    import xlrd
    workbook = xlrd.open_workbook(path, on_demand=True)
    for sheet in workbook.sheets():
        if sheet.nrows > 10000 or sheet.ncols > 100:
            raise ValueError('工作表超过10000行或100列')
        rows = []
        for row in sheet.get_rows():
            rows.append([xlrd.xldate.xldate_as_datetime(c.value, workbook.datemode).strftime('%Y-%m-%d') if c.ctype == xlrd.XL_CELL_DATE else c.value for c in row])
        sheets.append({'name': sheet.name, 'rows': rows})
elif extension == 'xlsx':
    import openpyxl
    with zipfile.ZipFile(path) as archive:
        if sum(i.file_size for i in archive.infolist()) > 50 * 1024 * 1024:
            raise ValueError('解压内容超过50MiB')
    workbook = openpyxl.load_workbook(path, data_only=True, read_only=True, keep_links=False)
    for sheet in workbook:
        if (sheet.max_row or 0) > 10000 or (sheet.max_column or 0) > 100:
            raise ValueError('工作表超过10000行或100列')
        rows = [[v.strftime('%Y-%m-%d') if isinstance(v, (datetime.date, datetime.datetime)) else v for v in r] for r in sheet.iter_rows(values_only=True)]
        sheets.append({'name': sheet.title, 'rows': rows})
elif extension == 'csv':
    with path.open(encoding='utf-8-sig', newline='') as stream:
        rows = list(csv.reader(stream))
    if len(rows) > 10000 or any(len(r) > 100 for r in rows):
        raise ValueError('工作表超过10000行或100列')
    sheets.append({'name': 'CSV', 'rows': rows})
else:
    raise ValueError('仅支持xls、xlsx、csv')
if len(sheets) > 50 or sum(len(s['rows']) for s in sheets) > 20000:
    raise ValueError('工作簿过大')
print(json.dumps(sheets, ensure_ascii=False, allow_nan=False))

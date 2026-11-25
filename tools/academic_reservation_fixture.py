"""Create the approved local academic fixture using the ordinary web workflows.

Run with --execute after --dry-run. Credentials and progress are protected with
Windows user-bound DPAPI in the ignored writable/private directory.
"""
import argparse
import base64
import ctypes
from ctypes import wintypes
from datetime import date, datetime, timedelta
from html.parser import HTMLParser
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
PROJECT = ROOT.parent
MANIFEST = ROOT / 'writable' / 'private' / 'academic-reservations-20261126.dpapi'
BASE = 'http://127.0.0.1:8083/index.php/'
STUDENTS = [
    ('202310001', 'Information Technology', 'business analytics workshop'),
    ('202310002', 'Information Technology', 'animation project rehearsal'),
    ('202310010', 'Information Technology', 'cybersecurity awareness workshop'),
    ('202310006', 'Computer Science', 'software design group presentation'),
    ('202510005', 'Computer Science', 'data science project consultation'),
    ('202310003', 'Computer Engineering', 'embedded systems demonstration'),
    ('202310007', 'Civil Engineering', 'structural design team review'),
    ('202410007', 'Mechanical Engineering', 'mechanical design presentation'),
    ('202410009', 'Electronics Engineering', 'electronics project discussion'),
    ('202610008', 'Electrical Engineering', 'electrical safety workshop'),
]
FACILITIES = [57, 61, 63]
EQUIPMENT = [102, 108]
NAMESPACE = uuid.UUID('8cd8f913-2c48-4711-90f1-a2407d5ee2bd')


class Blob(ctypes.Structure):
    _fields_ = [('cbData', wintypes.DWORD), ('pbData', ctypes.POINTER(ctypes.c_ubyte))]


def protect(data, decrypt=False):
    source = ctypes.create_string_buffer(data)
    incoming = Blob(len(data), ctypes.cast(source, ctypes.POINTER(ctypes.c_ubyte)))
    outgoing = Blob()
    crypt = ctypes.windll.crypt32
    if decrypt:
        ok = crypt.CryptUnprotectData(ctypes.byref(incoming), None, None, None, None, 1, ctypes.byref(outgoing))
    else:
        ok = crypt.CryptProtectData(ctypes.byref(incoming), None, None, None, None, 1, ctypes.byref(outgoing))
    if not ok:
        raise RuntimeError('Windows user-bound credential protection is unavailable.')
    try:
        return ctypes.string_at(outgoing.pbData, outgoing.cbData)
    finally:
        ctypes.windll.kernel32.LocalFree(outgoing.pbData)


def save(manifest):
    MANIFEST.parent.mkdir(parents=True, exist_ok=True)
    encoded = protect(json.dumps(manifest, separators=(',', ':')).encode())
    temp = MANIFEST.with_suffix('.tmp')
    temp.write_bytes(encoded)
    os.replace(temp, MANIFEST)


def load():
    return json.loads(protect(MANIFEST.read_bytes(), decrypt=True))


def build_plan():
    today = datetime.now().astimezone().date()
    # The workstation is configured for Asia/Manila. Compute Manila explicitly.
    from zoneinfo import ZoneInfo
    today = datetime.now(ZoneInfo('Asia/Manila')).date()
    days = []
    candidate = today + timedelta(days=1)
    while len(days) < 10:
        if candidate.weekday() < 5:
            days.append(candidate)
        candidate += timedelta(days=1)
    rows = []
    for index in range(20):
        sid, program, activity = STUDENTS[index // 2]
        kind = 'facility' if index < 7 else ('equipment' if index < 12 else 'combined')
        ids = []
        if kind != 'equipment': ids.append((FACILITIES[index % len(FACILITIES)], 1))
        if kind != 'facility': ids.append((EQUIPMENT[index % len(EQUIPMENT)], 1 + index % 2))
        start = '09:00' if index % 2 == 0 else '13:00'
        end = '10:00' if index % 2 == 0 else '14:30'
        purpose = 'Academic demonstration fixture: ' + activity + (' planning session' if index % 2 == 0 else ' practice session') + '. No real teacher signature.'
        rows.append({'index': index, 'student_id': sid, 'program': program, 'date': days[index // 2].isoformat(),
                     'start_time': start, 'end_time': end, 'items': ids, 'purpose': purpose, 'kind': kind,
                     'submission_key': str(uuid.UUID(bytes=uuid.uuid5(NAMESPACE, f'{sid}-{index % 2}').bytes, version=4)),
                     'candidate': 'approval' if index < 8 else ('rejection' if index < 11 else ('cancellation' if index < 14 else 'remain pending'))})
    return rows


# Runs only fixed, read-only SELECTs; connection credentials stay in PHP memory.
PREFLIGHT = r'''
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function connectFixture($app,$expected) {
 $cfg=[]; foreach(file('C:/Projects/TechReserve-Project/'.$app.'/.env') as $line)
  if(preg_match('/^\s*database\.default\.(hostname|username|password|database|port)\s*=\s*(.*?)\s*$/',$line,$m)) $cfg[$m[1]]=trim($m[2], " \t\n\r\0\x0B\"'");
 if(!in_array(strtolower($cfg['hostname']??''),['localhost','127.0.0.1','::1'],true)||($cfg['database']??'')!==$expected||(int)($cfg['port']??0)!==33307) throw new Exception('Unexpected local database target.');
 $db=new mysqli($cfg['hostname'],$cfg['username'],$cfg['password'],$cfg['database'],(int)$cfg['port']);
 $target=$db->query('SELECT DATABASE() db, @@port port')->fetch_assoc(); if($target['db']!==$expected||(int)$target['port']!==33307) throw new Exception('Unexpected database connection.'); return $db;
}
try {
 $plan=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$tr=connectFixture('techreserve','techreserve_db');$sr=connectFixture('student-records','student_records_db');$out=['students'=>[],'users'=>[],'reservations'=>[],'resources'=>[],'baseline'=>[]];
 foreach(['users'=>"SELECT COUNT(*) n FROM users WHERE role='borrower'",'reservations'=>'SELECT COUNT(*) n FROM reservations','documents'=>'SELECT COUNT(*) n FROM reservation_documents','payments'=>'SELECT COUNT(*) n FROM payments','outbox'=>'SELECT COUNT(*) n FROM notification_email_outbox'] as $k=>$q)$out['baseline'][$k]=(int)$tr->query($q)->fetch_assoc()['n'];
 foreach(array_unique(array_column($plan,'student_id')) as $id){$q=$sr->prepare('SELECT student_id,full_name,institutional_email,program,program_code,enrollment_status FROM students WHERE student_id=?');$q->bind_param('s',$id);$q->execute();$row=$q->get_result()->fetch_assoc();if(!$row||$row['enrollment_status']!=='enrolled'||$row['institutional_email']!=='student-'.$id.'@example.invalid')throw new Exception('A selected fictional student is not eligible.');$out['students'][$id]=$row;$q->close();$q=$tr->prepare('SELECT id,student_id,email,role,status FROM users WHERE student_id=?');$q->bind_param('s',$id);$q->execute();if($row=$q->get_result()->fetch_assoc())$out['users'][$id]=$row;$q->close();}
 foreach([57,61,63,102,108] as $id){$q=$tr->prepare('SELECT id,name,resource_type,status,deleted_at,quantity,rate_per_hour,conflict_group,conflict_group_alt FROM resources WHERE id=?');$q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();if(!$row||$row['status']!=='available'||$row['deleted_at']!==null||$row['conflict_group']!==null||$row['conflict_group_alt']!==null)throw new Exception('A planned resource is unavailable or has a conflict group.');$out['resources'][$id]=$row;$q->close();}
 foreach($plan as $item){$key=$item['submission_key'];$q=$tr->prepare('SELECT id,user_id,status,total_amount FROM reservations WHERE submission_key=?');$q->bind_param('s',$key);$q->execute();if($row=$q->get_result()->fetch_assoc())$out['reservations'][$key]=$row;$q->close();$local=new DateTimeZone('Asia/Manila');$utc=new DateTimeZone('UTC');$a=(new DateTimeImmutable($item['date'].' '.$item['start_time'],$local))->setTimezone($utc)->format('Y-m-d H:i:s');$b=(new DateTimeImmutable($item['date'].' '.$item['end_time'],$local))->setTimezone($utc)->format('Y-m-d H:i:s');foreach($item['items'] as [$id,$qty]){$q=$tr->prepare("SELECT COALESCE(SUM(ri.quantity),0) used FROM reservation_items ri JOIN reservations r ON r.id=ri.reservation_id WHERE ri.resource_id=? AND r.status='approved' AND r.start_datetime < ? AND r.end_datetime > ?");$q->bind_param('iss',$id,$b,$a);$q->execute();$used=(int)$q->get_result()->fetch_assoc()['used'];$q->close();if($used+$qty>(int)$out['resources'][$id]['quantity'])throw new Exception('A proposed slot has insufficient availability.');}}
 echo json_encode($out,JSON_THROW_ON_ERROR);
} catch(Throwable $e){fwrite(STDERR,'Preflight failed: '.$e->getMessage());exit(1);}
'''


def preflight(plan):
    result = subprocess.run(['php', '-r', PREFLIGHT], input=json.dumps(plan), text=True, capture_output=True, cwd=ROOT)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or 'Read-only preflight failed.')
    return json.loads(result.stdout)


class Inputs(HTMLParser):
    def __init__(self):
        super().__init__(); self.values = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name') and attrs.get('value') is not None:
            self.values[attrs['name']] = attrs['value']


class Errors(HTMLParser):
    def __init__(self):
        super().__init__(); self.depth = 0; self.messages = []
    def handle_starttag(self, tag, attrs):
        classes = dict(attrs).get('class', '').split()
        if self.depth: self.depth += 1
        elif 'alert' in classes and 'error' in classes: self.depth = 1
    def handle_endtag(self, tag):
        if self.depth: self.depth -= 1
    def handle_data(self, value):
        if self.depth and value.strip(): self.messages.append(value.strip())


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None


class Browser:
    def __init__(self): self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()), NoRedirect())
    def request(self, path, body=None, content_type=None):
        headers = {'User-Agent': 'AcademicFixture/1.0'}
        if content_type: headers['Content-Type'] = content_type
        request = urllib.request.Request(BASE + path, data=body, headers=headers)
        try: response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error: response = error
        with response: return response.status, dict(response.headers), response.read().decode('utf-8', errors='replace')
    def form(self, path):
        status, _, body = self.request(path)
        if status != 200: raise RuntimeError(f'Form {path} returned HTTP {status}.')
        parsed = Inputs(); parsed.feed(body)
        if not parsed.values.get('csrf_test_name'): raise RuntimeError('A form did not provide a CSRF token.')
        return parsed.values
    def post(self, path, values):
        return self.request(path, urllib.parse.urlencode(values).encode(), 'application/x-www-form-urlencoded')


def pdf_for(row):
    text = f"ACADEMIC DEMONSTRATION ONLY - NO TEACHER SIGNATURE - {row['student_id']} - {row['index']+1}"
    content = f'BT /F1 12 Tf 36 750 Td ({text}) Tj ET'.encode('ascii')
    objects = [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
               b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
               b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
               b'<< /Length ' + str(len(content)).encode() + b' >>\nstream\n' + content + b'\nendstream']
    result = b'%PDF-1.4\n'; offsets = [0]
    for number, obj in enumerate(objects, 1):
        offsets.append(len(result)); result += str(number).encode() + b' 0 obj\n' + obj + b'\nendobj\n'
    xref = len(result); result += b'xref\n0 6\n0000000000 65535 f \n'
    for offset in offsets[1:]: result += f'{offset:010d} 00000 n \n'.encode()
    return result + f'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode()


def multipart(values, document):
    boundary = 'Fixture' + secrets.token_hex(16); chunks = []
    for name, value in values:
        chunks += [f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()]
    chunks += [f'--{boundary}\r\nContent-Disposition: form-data; name="request_form"; filename="ACADEMIC-DEMONSTRATION-UNSIGNED.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode(), document, b'\r\n', f'--{boundary}--\r\n'.encode()]
    return b''.join(chunks), 'multipart/form-data; boundary=' + boundary


def values_for(row, csrf):
    values = [('csrf_test_name', csrf), ('submission_key', row['submission_key']), ('date', row['date']),
              ('end_date', row['date']), ('start_time', row['start_time']), ('end_time', row['end_time']), ('purpose', row['purpose'])]
    for index, (rid, qty) in enumerate(row['items']):
        values.extend([(f'items[{index}][resource_id]', rid), (f'items[{index}][quantity]', qty)])
    return values


def main():
    parser=argparse.ArgumentParser(); parser.add_argument('--execute', action='store_true'); args=parser.parse_args()
    if sys.platform != 'win32': raise RuntimeError('The fixture requires Windows user-bound credential protection.')
    manifest = load() if MANIFEST.exists() else None
    if manifest and any(uuid.UUID(row['submission_key']).version != 4 for row in manifest['plan']):
        if manifest['reservations']:
            raise RuntimeError('The checkpoint contains an incompatible submission key with existing reservations.')
        for row in manifest['plan']:
            row['submission_key'] = str(uuid.UUID(bytes=uuid.uuid5(NAMESPACE, f"{row['student_id']}-{row['index'] % 2}").bytes, version=4))
        save(manifest)
    plan = manifest['plan'] if manifest else build_plan()
    info = preflight(plan)
    if manifest is None and (info['users'] or info['reservations']):
        raise RuntimeError('A selected account or fixture key already exists without the protected checkpoint; refusing to claim it.')
    print('Preflight: 10 enrolled fictional students; 20 pending requests; 7 facility, 5 equipment, 8 combined.')
    print('Schedules:', plan[0]['date'], 'through', plan[-1]['date'], 'Asia/Manila.')
    print('Current totals:', json.dumps(info['baseline'], sort_keys=True))
    if not args.execute:
        print('Dry run only: no application records created.'); return
    if manifest is None:
        manifest = {'version':1, 'plan':plan, 'passwords':{sid:secrets.token_urlsafe(24) for sid,_,_ in STUDENTS}, 'reservations':{}, 'baseline':info['baseline']}
        save(manifest)
    for sid, _, _ in STUDENTS:
        browser = Browser(); password = manifest['passwords'][sid]
        if sid not in info['users']:
            fields = browser.form('register'); status, headers, _ = browser.post('register', {'csrf_test_name':fields['csrf_test_name'], 'student_id':sid, 'password':password, 'password_confirm':password})
            if status not in (302, 303) or not headers.get('Location','').rstrip('/').endswith('/login'):
                raise RuntimeError(f'Registration for {sid} did not succeed (HTTP {status}); existing progress retained.')
            print('Registered fictional borrower', sid, flush=True)
        fields=browser.form('login'); status, headers, _=browser.post('login', {'csrf_test_name':fields['csrf_test_name'], 'student_id':sid, 'password':password})
        if status not in (302, 303) or 'borrower/dashboard' not in headers.get('Location',''):
            raise RuntimeError(f'Borrower login for {sid} failed (HTTP {status}); existing progress retained.')
        for row in (x for x in plan if x['student_id']==sid):
            if row['submission_key'] in info['reservations']:
                existing=info['reservations'][row['submission_key']]
                if sid not in info['users'] or int(existing['user_id']) != int(info['users'][sid]['id']): raise RuntimeError('Existing fixture ownership mismatch.')
                manifest['reservations'][row['submission_key']] = int(existing['id']); save(manifest); continue
            fields=browser.form('borrower/reservations/new')
            status, _, preview=browser.post('borrower/reservations/preview', values_for(row,fields['csrf_test_name']))
            if status != 200 or 'Availability and fee preview' not in preview:
                raise RuntimeError(f'Availability preview failed for fixture {row["index"]+1} (HTTP {status}); existing progress retained.')
            parsed=Inputs(); parsed.feed(preview); csrf=parsed.values.get('csrf_test_name')
            if not csrf: raise RuntimeError('Preview did not return a CSRF token.')
            body, content_type=multipart(values_for(row,csrf),pdf_for(row))
            status,headers,result_body=browser.request('borrower/reservations',body,content_type)
            match=re.search(r'/borrower/reservations/(\d+)(?:$|[?#])',headers.get('Location',''))
            if status not in (302, 303) or not match:
                errors=Errors(); errors.feed(result_body)
                detail = '; '.join(errors.messages) or 'No validation detail returned.'
                raise RuntimeError(f'Reservation fixture {row["index"]+1} failed (HTTP {status}): {detail}; existing progress retained.')
            manifest['reservations'][row['submission_key']]=int(match.group(1));save(manifest)
            print(f'Submitted RES-{match.group(1)} for {sid}', flush=True)
    print('Completed; protected progress checkpoint:', MANIFEST)


if __name__ == '__main__':
    try: main()
    except Exception as error: print('Fixture stopped:', error, file=sys.stderr); sys.exit(1)

import json
import urllib.request
import http.cookiejar

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
base = 'http://localhost:8080/backend/api'

# Login
print('=== LOGIN ===')
req = urllib.request.Request(base + '/auth.php?action=login', data=json.dumps({'username': 'admin@emarket.com', 'password': '123456'}).encode(), headers={'Content-Type': 'application/json'})
r = opener.open(req)
print('status', r.status)
print(r.read().decode())

endpoints = [
    'products.php',
    'movements.php?action=validated-exits',
    'quotes.php',
    'invoices.php',
    'payments.php',
    'users.php',
    'categories.php',
    'stats.php',
    'activity.php?limit=10'
]

for ep in endpoints:
    print('\n---', ep, '---')
    try:
        req = urllib.request.Request(base + '/' + ep)
        r = opener.open(req)
        text = r.read().decode()
        try:
            payload = json.loads(text)
        except Exception:
            payload = {'raw': text}
        if isinstance(payload, dict) and payload.get('success') is True and 'data' in payload:
            data = payload['data']
            print('status', r.status, 'data_type', type(data).__name__, 'len', len(data) if hasattr(data, '__len__') else 'N/A')
            if isinstance(data, list) and data:
                print('sample keys:', list(data[0].keys())[:12])
            elif isinstance(data, dict):
                print('keys:', list(data.keys())[:12])
        else:
            print('status', r.status, 'payload keys', list(payload.keys()) if isinstance(payload, dict) else 'raw')
    except Exception as e:
        print('ERROR', e)

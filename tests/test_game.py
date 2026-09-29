"""Run isolated API regressions: python tests/test_game.py /path/to/php.exe."""
import concurrent.futures
import hashlib
import http.cookiejar
import json
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = sys.argv[1] if len(sys.argv) > 1 else 'php'

class Client:
    def __init__(self, base):
        self.base = base
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(self, action, **body):
        data = None if action == 'state' else json.dumps(body).encode()
        req = urllib.request.Request(self.base+'?action='+action, data=data, headers={'Content-Type':'application/json'})
        return json.load(self.http.open(req, timeout=10))

    def ok(self, action, **body):
        result = self.call(action, **body)
        assert result['status'] == 'ok', (action, result)
        return result

def run(base, second_base):
    a,b,c,d = [Client(base) for _ in range(4)]
    created = a.ok('create_room', name='Host'); code = created['code']; aid = created['player_id']
    assert a.ok('create_room', name='Host')['code'] == code
    bid = b.ok('join_room', code=code, name='B')['player_id']
    cid = c.ok('join_room', code=code, name='C')['player_id']
    did = d.ok('join_room', code=code, name='D')['player_id']
    assert b.ok('join_room', code=code, name='B')['player_id'] == bid
    assert len(a.ok('state')['players']) == 4
    assert Client(base).call('join_room',code=code,name='B')['status']=='error'
    assert a.call('add_bot')['status']=='error'
    assert b.call('kick_player',player_id=cid)['status']=='error'
    a.ok('kick_player',player_id=did)
    assert d.call('state')['status']=='no_room'
    assert len(a.ok('state')['players'])==3
    # Recover a lost session using the browser's saved token, even when full.
    a2=Client(base)
    assert a2.ok('join_room',code=code,name='Host',resume_token=created['resume_token'])['player_id']==aid
    a.ok('start_game')
    a.ok('set_code',secret='1357'); b.ok('set_code',secret='2468'); c.ok('set_code',secret='9081')
    order=a.ok('state')['turn_order']; assert order==[aid,bid,cid]
    b2=Client(base)
    assert b2.ok('join_room',code=code,name='B',secret='2468')['player_id']==bid
    assert b2.ok('state')['players'][bid]['secret']=='2468'
    assert Client(base).call('join_room',code=code,name='New')['status']=='error'
    assert a.call('set_code',secret='0000')['status']=='error'
    assert a.call('attack',target_id=aid,guess='0000')['status']=='error'
    a.ok('attack',target_id=bid,guess='0000')
    assert a.call('attack',target_id=bid,guess='0000')['status']=='error'
    a.ok('attack',target_id=cid,guess='0000')
    assert a.ok('state')['current_turn_player_id']==bid
    pending=b.ok('state')['history'][0]
    assert b.call('confirm',attack_id=pending['id'],hits=4)['status']=='error'
    b.ok('confirm',attack_id=pending['id'],hits=0)
    b.ok('attack',target_id=aid,guess='0000'); b.ok('attack',target_id=cid,guess='0000')
    # A has a pending attack but must not jump ahead of C.
    assert a.ok('state')['current_turn_player_id']==cid
    for h in c.ok('state')['history']:
        if h['target_id']==cid and not h['confirmed']: c.ok('confirm',attack_id=h['id'],hits=1)
    c.ok('attack',target_id=aid,guess='0000'); c.ok('attack',target_id=bid,guess='2468')
    state=a.ok('state'); assert state['round']==2 and state['current_turn_player_id']==aid
    assert state['turn_order']==order
    assert all(h['attacker_id']==aid or h['target_id']==aid for h in state['history'])
    fatal=next(h for h in b.ok('state')['history'] if h['guess']=='2468')
    b.ok('confirm',attack_id=fatal['id'],hits=4)
    spectator=b.ok('state'); assert spectator['spectator'] and len(spectator['history'])==6
    assert len(spectator['players'])==3
    assert Client(base).call('join_room',code=code,name='B',secret='2468')['status']=='error'
    for h in a.ok('state')['history']:
        if h['target_id']==aid and not h['confirmed']: a.ok('confirm',attack_id=h['id'],hits=0)
    a.ok('attack',target_id=cid,guess='9081')
    fatal=next(h for h in c.ok('state')['history'] if h['guess']=='9081')
    c.ok('confirm',attack_id=fatal['id'],hits=4)
    state=a.ok('state'); assert state['room_status']=='finished' and state['winner_id']==aid
    assert state['current_turn_player_id'] is None and len(state['history'])==7
    assert a.call('attack',target_id=cid,guess='9081')['status']=='error'
    # Removing the unready player must unblock setup; removing the host transfers ownership.
    x,y,z=[Client(base) for _ in range(3)]
    room=x.ok('create_room',name='X'); rc=room['code']
    yid=y.ok('join_room',code=rc,name='Y')['player_id']; zid=z.ok('join_room',code=rc,name='Z')['player_id']
    x.ok('start_game'); x.ok('set_code',secret='1234'); y.ok('set_code',secret='5678')
    x.ok('kick_player',player_id=zid); assert x.ok('state')['room_status']=='playing'
    x.ok('kick_player',player_id=yid); assert x.ok('state')['room_status']=='finished'
    assert y.call('state')['status']=='no_room'
    # Independent sessions creating rooms must not overwrite each other's JSON changes.
    def create(i): return Client(base if i%2 else second_base).ok('create_room',name='Concurrent'+str(i))['code']
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool: codes=list(pool.map(create,range(12)))
    assert len(set(codes))==12
    for rc in codes: Client(base).ok('join_room',code=rc,name='Guest')
    # Bots, capacity, and setup readiness.
    bot=Client(base); bot.ok('create_room',name='BotHost'); bot.ok('add_bot'); bot.ok('add_bot')
    assert bot.call('add_bot')['status']=='error'
    bot.ok('remove_bot'); bot.ok('start_game'); bot.ok('set_code',secret='1234')
    st=bot.ok('state'); bots=[p for p in st['players'].values() if p['is_bot']]
    for target in bots: bot.ok('attack',target_id=target['id'],guess='0000')
    st=bot.ok('state'); assert st['round']==2 and st['is_my_turn'] and len(st['history'])==2, st
    print('PASS: rejoin, capacity, kick, token/PIN recovery, fixed turns, spectator history, victory, validation, bots, independent writes')

if __name__=='__main__':
    original=hashlib.sha256((ROOT/'rooms.json').read_bytes()).digest()
    with tempfile.TemporaryDirectory(prefix='codebreak-tests-') as tmp:
        shutil.copy2(ROOT/'index.php',Path(tmp)/'index.php')
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        with open(Path(tmp)/'server.log','w+') as log:
            process=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',tmp],stdout=log,stderr=log)
            with socket.socket() as sock: sock.bind(('127.0.0.1',0)); second_port=sock.getsockname()[1]
            second=subprocess.Popen([PHP,'-S',f'127.0.0.1:{second_port}','-t',tmp],stdout=log,stderr=log)
            try:
                for server_port in (port,second_port):
                    for _ in range(50):
                        try:
                            with socket.create_connection(('127.0.0.1',server_port),timeout=.1): break
                        except OSError: time.sleep(.1)
                run(f'http://127.0.0.1:{port}/',f'http://127.0.0.1:{second_port}/')
            finally:
                process.terminate(); second.terminate()
                process.wait(timeout=10); second.wait(timeout=10)
    assert hashlib.sha256((ROOT/'rooms.json').read_bytes()).digest()==original

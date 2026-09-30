const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const page = fs.readFileSync(path.join(__dirname, '..', 'index.php'), 'utf8');
const script = page.split('<script>')[1].split('</script>')[0];
const nodes = new Map();
const element = id => {
  if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', style: {}, hasChildNodes: () => true });
  return nodes.get(id);
};
const context = vm.createContext({
  document: { getElementById: element },
  fetch: async () => ({ json: async () => ({status:'no_room'}) }),
  localStorage: { getItem: () => null },
  setTimeout, clearTimeout, AbortController, alert: () => {},
});
vm.runInContext(script, context);
const players = {
  p_a: {id:'p_a',name:'Host',is_me:true,is_host:true,secret:'1234',eliminated:false},
  p_b: {id:'p_b',name:'Guest',is_me:false,is_host:false,secret:'',eliminated:false},
};
const state = {players, my_id:'p_a', host_id:'p_a', is_host:true, room_code:'ABC', code_length:4,
  room_status:'playing',round:2,turn_order:['p_a','p_b'],current_turn_player_id:'p_b',attacks_this_turn:{},history:[]};
context.rLobby(state);
const kick = element('lplayers').innerHTML.match(/onclick="(doKick[^\"]+)"/)[1];
assert.equal(kick,"doKick('p_b')");
let kicked;
vm.runInNewContext(kick,{doKick:id=>kicked=id});
assert.equal(kicked,'p_b');
context.rSetup(state);
assert.equal(element('swaiting').style.display,'block');
assert.ok(element('splayers').innerHTML.includes("doKick('p_b')"));
assert.equal(context.esc('<img src=x onerror=alert(1)>'), '&lt;img src=x onerror=alert(1)&gt;');
// A spectator must see every entry, including attacks against themselves beyond the old 28-entry cap.
players.p_a.eliminated=true;state.spectator=true;
state.history=Array.from({length:65},(_,i)=>({id:'a_'+i,attacker_id:'p_b',target_id:'p_a',guess:'9876',hits:0,confirmed:true,round:i+1}));
context.rPlayers=()=>{};
context.rGame(state);
const cardHistory=context.attackGridHTML(state,state.history);
assert.equal((cardHistory.match(/9876/g)||[]).length,65);
assert.ok(cardHistory.includes('Раунд 65'));
assert.ok(!page.includes('id="g-history"'));
state.history.push({id:'live',attacker_id:'p_b',target_id:'p_a',guess:'4567',confirmed:false,round:66});
context.rGame(state);
assert.ok(element('g-cf-box').innerHTML.includes('4567'));
assert.ok(element('g-cf-box').innerHTML.includes('Guest → Host'));
assert.ok(element('g-cf-box').innerHTML.includes('ожидает ответа'));
state.history.at(-1).confirmed=true;state.history.at(-1).hits=2;
context.rGame(state);
assert.ok(element('g-cf-box').innerHTML.includes('2 совп.'));
state.room_status='finished';state.winner_id='p_b';
context.rGame(state);
assert.ok(element('g-cf-box').innerHTML.includes('Победитель: Guest'));
assert.equal(element('g-cf-actions').innerHTML,'');
console.log('PASS: kick handler, escaping, complete spectator history, winner display');

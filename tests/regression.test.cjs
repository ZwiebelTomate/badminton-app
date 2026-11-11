const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const path=require('node:path');
const html=fs.readFileSync(path.join(__dirname,'../index.html'),'utf8');
function section(start,end){return html.slice(html.indexOf(start),html.indexOf(end,html.indexOf(start)));}
function context(now='2026-11-11T19:00:00'){
 const nodes={};
 class Clock extends Date{constructor(...args){super(...(args.length?args:[now]));}static now(){return new Date(now).getTime();}}
 const c=vm.createContext({Date:Clock,document:{getElementById(id){return nodes[id]??={innerHTML:'',style:{},classList:{toggle(){}},setAttribute(k,v){this[k]=v;}};}},state:{appointments:[],players:[]},getMe:()=> 'Marco',today:()=> '2026-11-11',cashBalances:()=>({}),money:String,appointmentHistoryVisible:true,appointmentCardHtml:a=>`[${a.id}]`,pollList:[],pollHistoryVisible:false,pollCurrent:null,pollExpanded:false,pollHasVoted:false,renderPollApproval:()=>c.approvals++,approvals:0});
 vm.runInContext(html.match(/^function esc\(s\).*$/m)[0],c);
 return {c,nodes};
}
// Extract complete named functions using their following declaration boundary.
function load(c,start,end){vm.runInContext(section(start,end),c);}
test('all inline scripts and service worker parse',()=>{
 for(const m of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi))new vm.Script(m[1]);
 new vm.Script(fs.readFileSync(path.join(__dirname,'../sw.js'),'utf8'));
});
function setup(now){const x=context(now);load(x.c,'function appointmentEndDate(','function shareAllAppointments(');return x;}
test('next card and appointment list agree before, at and after end, including midnight',()=>{
 for(const [now,appt,visible] of [
 ['2026-11-11T19:00:00',{from:'18:30',to:'20:00'},true],
 ['2026-11-11T20:00:00',{from:'18:30',to:'20:00'},false],
 ['2026-11-11T21:00:00',{from:'18:30',to:'20:00'},false],
 ['2026-11-12T00:30:00',{from:'23:00',to:'01:00'},true],
 ['2026-11-12T01:00:00',{from:'23:00',to:'01:00'},false],
 ['2026-11-11T19:00:00',{from:'18:30:00',to:'20:00:00'},true]]){
  const {c,nodes}=setup(now);c.state.appointments=[{id:1,date:'2026-11-11',participants:['Marco'],...appt}];
  load(c,'function renderMyNextAppointment(','function forceStandardHomeOrder(');
  load(c,'function renderAppointments(','function callBadminton(');
  c.renderMyNextAppointment();c.renderAppointments();
  assert.equal(nodes.myNextAppointmentCard.innerHTML.length>0,visible);
  assert.equal(nodes.appointmentList.innerHTML.includes('[1]'),visible);
  assert.equal(nodes.appointmentHistory.innerHTML.includes('[1]'),!visible);
 }
});
test('poll history expands, escapes names, sorts recent first and excludes open polls',()=>{
 const {c,nodes}=setup();
 load(c,'function pollDate(','let pollHistoryVisible=');
 load(c,'function togglePollHistory(','async function loadPolls(');
 load(c,'function pollWeekNumber(','function pollToggle(');
 const poll=(date,status='closed')=>({closes_at:date,status,weeks:[['2026-11-10']],results:[[{date:'2026-11-10',players:['<img>'],count:1}]],approved:{dates:['2026-11-10']},voters:['Marco'],total:1});
 c.pollList=[poll('2026-11-09 18:00:00'),poll('2026-11-10 18:00:00'),poll('2026-11-13 18:00:00','open')];
 c.renderPollHistory();assert.equal(nodes.pollHistoryContent.style.display,'none');
 c.togglePollHistory();assert.equal(nodes.pollHistoryToggle['aria-expanded'],'true');
 const rendered=nodes.pollHistoryContent.innerHTML;
 assert.ok(rendered.includes('&lt;img&gt;'));assert.ok(!rendered.includes('<img>'));
 assert.ok(rendered.indexOf('10.11.2026')<rendered.indexOf('09.11.2026'));
 assert.ok(!rendered.includes('13.11.2026'));assert.ok(!rendered.includes('Termin bearbeiten'));
 c.togglePollHistory();assert.equal(nodes.pollHistoryToggle['aria-expanded'],'false');
});
test('collapsed active poll refreshes approval section',()=>{
 const {c}=setup();c.pollCurrent=1;c.pollList=[{id:1,status:'open',closes_at:'2026-11-13 18:00:00',voters:[],total:1}];
 load(c,'function renderPolls(','function pollWeekNumber(');c.renderPolls();assert.equal(c.approvals,1);
});

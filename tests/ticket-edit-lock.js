const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const controls = [
  ['csrf','hidden'],['id','hidden'],['return_query','hidden'],['complainant','text'],
  ['request_text','textarea'],['solution_text','textarea'],['client_id','select'],['occurred_at','date'],
  ['status','select'],['service_amount','number'],['materials[0][quantity]','number'],
  ['materials[0][unit_price]','number'],['notes','textarea'],['addMaterial','button'],['save','submit']
].map(([name,type]) => ({name,type,disabled:false}));
let handler;
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/assets/js/ticket-edit-lock.js'),'utf8'), {
  document:{addEventListener(event,fn){assert.equal(event,'DOMContentLoaded');handler=fn;},
    querySelectorAll(selector){assert.equal(selector,'form[data-ticket-edit-locked]');return [{querySelectorAll(){return controls;}}];}}
});
handler();
let checks=0;
for(const control of controls){
  const allowed = ['csrf','id','return_query','complainant','request_text','solution_text','save'].includes(control.name);
  assert.equal(control.disabled,!allowed,control.name);checks++;
}
const before=JSON.stringify(controls);handler();assert.equal(JSON.stringify(controls),before);checks++;
console.log(JSON.stringify({checks,failures:[],scope:'Tres campos textuais e envio disponiveis; controles financeiros e de material desabilitados no formulario bloqueado.'},null,2));

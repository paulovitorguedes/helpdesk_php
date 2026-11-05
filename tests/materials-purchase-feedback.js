const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../public/assets/js/materials-purchase.js'), 'utf8');
let checks = 0;
async function scenario(response, reject = false, concurrent = false) {
  const values = {purchase_date:'2026-11-04', supplier:'Fornecedor & NF', quantity:'3', total_cost:'60.90', purchase_notes:'Texto preenchido'};
  const before = JSON.stringify(values);
  const feedback = {hidden:true, textContent:'', focus(){this.focused=true;}};
  const button = {disabled:false}; let handler, calls=0, navigations=[];
  const form = {values, querySelector(s){return s.includes('feedback')?feedback:button;}, getAttribute(){return null;}, addEventListener(type, callback){handler=callback;}};
  let release;
  const gate = new Promise(resolve => release = resolve);
  const fetch = async (url, options) => {
    calls++; assert.equal(url,'http://local/materials.php?purchase=200');
    assert.deepEqual(options.body.values,values); assert.equal(options.headers.Accept,'application/json');
    if(concurrent)await gate;
    if(reject)throw new Error('network');
    return {redirected:false, status:409, ok:false, headers:{get(){return 'application/json';}}, json:async()=>({ok:false,message:'A data não pode ser anterior.'}),...response};
  };
  const window = {fetch, location:{href:'http://local/materials.php?purchase=200',assign(url){navigations.push(url);}}};
  vm.runInNewContext(script,{window,fetch,document:{querySelectorAll(){return [form];}},FormData:class{constructor(f){this.values={...f.values};}}});
  const event = ()=>({defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}});
  const first = event(); const task = handler(first);
  if(concurrent){await handler(event());assert.equal(calls,1);checks++;release();}
  await task;
  assert.equal(first.defaultPrevented,true);assert.equal(JSON.stringify(values),before);assert.equal(button.disabled,false);checks+=3;
  return {feedback,navigations,calls,handler,event,values};
}
(async()=>{
  for(const status of [409,422,500]){const s=await scenario({status});assert.equal(s.feedback.hidden,false);assert.deepEqual(s.navigations,[]);checks+=2;}
  for(const response of [{status:419,headers:{get(){return 'text/html';}}},{redirected:true},{json:async()=>{throw new Error('invalid JSON');}}]){
    const s=await scenario(response);assert.equal(s.feedback.hidden,false);assert.deepEqual(s.navigations,[]);checks+=2;
  }
  const network=await scenario({},true);assert.equal(network.calls,1);assert.deepEqual(network.navigations,[]);checks+=2;
  const duplicate=await scenario({},false,true);assert.equal(duplicate.calls,1);checks++;
  const success=await scenario({status:200,ok:true,json:async()=>({ok:true,redirect:'/materials.php'})});assert.deepEqual(success.navigations,['/materials.php']);checks++;
  console.log(JSON.stringify({checks,failures:[],scope:'Comportamento JavaScript: preservacao dos campos, erros, rede, sessao, envio duplo e navegacao somente no sucesso.'},null,2));
})().catch(error=>{console.error(error);process.exitCode=1;});

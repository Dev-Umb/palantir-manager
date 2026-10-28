const KEY = 'xyc-demo-v1';
const customers = [
 {id:'c1',name:'远山桥梁工程（虚构）',address:'演示工业园 A 区',contacts:[{name:'陈工（演示）',phone:'未提供',job:'项目对接'}]},
 {id:'c2',name:'海川建设（虚构）',address:'演示工业园 B 区',contacts:[{name:'林工（演示）',phone:'未提供',job:'采购对接'}]}
];
function initial() { return {role:'sales',loggedIn:true,read:[],projects:[
 {id:'p1',code:'DEMO-001',name:'滨江大桥钢模板',customerId:'c1',owner:'演示业务员',status:'进行中',contractAmount:1260000,incurred:800000,paid:560000,unpaid:240000,lastPayment:'2026-10-20',remark:'所有信息均为虚构测试数据',contracts:[{id:'h1',name:'钢模板销售合同',type:'销售合同',amount:1260000,date:'2026-09-15',qty:'120 吨',attachment:'示例合同.pdf（待接入）'}]},
 {id:'p2',code:'DEMO-002',name:'北站立交墩柱模板',customerId:'c2',owner:'演示业务员',status:'待签约',contractAmount:null,incurred:null,paid:null,unpaid:null,lastPayment:'—',remark:'金额待确认，不按零处理',contracts:[]},
 {id:'p3',code:'DEMO-003',name:'产业园钢结构加工',customerId:'c2',owner:'其他业务员',status:'已完成',contractAmount:360000,incurred:360000,paid:360000,unpaid:0,lastPayment:'2026-10-18',remark:'管理员可见的相邻权限样例',contracts:[{id:'h3',name:'加工服务合同',type:'加工合同',amount:360000,date:'2026-08-11',qty:'80 吨',attachment:'加工合同.pdf（待接入）'}]},
 {id:'p4',code:'DEMO-004',name:'试制模板样件',customerId:'c1',owner:'演示业务员',status:'进行中',contractAmount:0,incurred:0,paid:0,unpaid:0,lastPayment:'—',remark:'零金额与零分母样例',contracts:[]}
]}; }
function load(){ const state=wx.getStorageSync(KEY); return state && state.projects ? state : initial(); }
function write(state){ wx.setStorageSync(KEY,state); return state; }
function money(n){ return n === null || n === undefined ? '—' : Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function progress(p){ return p.incurred > 0 && p.paid !== null && p.paid !== undefined ? (p.paid / p.incurred * 100).toFixed(1)+'%' : '—'; }
function visible(state){ return state.loggedIn ? state.projects.filter(p=>state.role==='admin'||p.owner==='演示业务员') : []; }
function decorate(p){return Object.assign({},p,{customer:customers.find(c=>c.id===p.customerId).name,amountText:money(p.contractAmount),paidText:money(p.paid),unpaidText:money(p.unpaid),incurredText:money(p.incurred),progress:progress(p),contracts:p.contracts.map(c=>Object.assign({},c,{amountText:money(c.amount)}))});}
function projects(query='',status='全部',customerId=''){ return visible(load()).filter(p=>(!customerId||p.customerId===customerId)&&(status==='全部'||p.status===status)&&[p.name,p.code,customers.find(c=>c.id===p.customerId).name].join(' ').toLowerCase().includes(query.trim().toLowerCase())).map(decorate); }
function project(id){return projects().find(p=>p.id===id)||null;}
function summary(){ const rows=visible(load()); const result={count:rows.length}; ['contractAmount','paid','unpaid'].forEach(k=>{ const known=rows.filter(p=>p[k]!==null&&p[k]!==undefined); result[k]=money(known.reduce((sum,p)=>sum+p[k],0)); result[k+'Missing']=rows.length-known.length; }); return result; }
function save(input,id){
 const state=load(); if(!state.loggedIn)throw Error('请先进入演示账号');
 const old=id?visible(state).find(p=>p.id===id):null;
 if(id&&!old)throw Error('无权编辑此项目');
 const name=String(input.name||'').trim(); if(!name)throw Error('请输入项目名称'); if(name.length>60)throw Error('项目名称不能超过60字');
 if(!customers.some(c=>c.id===input.customerId))throw Error('请选择客户');
 if(!['进行中','待签约','已完成'].includes(input.status))throw Error('请选择有效状态');
 let stamp=Date.now();while(state.projects.some(p=>p.id==='p'+stamp))stamp++;
 const p=Object.assign({},old||{id:'p'+stamp,code:'DEMO-'+stamp,owner:'演示业务员',contracts:[],contractAmount:null,paid:null,unpaid:null,incurred:null,lastPayment:'—'}, {name,customerId:input.customerId,status:input.status,remark:String(input.remark||'').slice(0,500)});
 if(old) state.projects=state.projects.map(row=>row.id===id?p:row); else state.projects.unshift(p);
 write(state); return p.id;
}
function setRole(role){if(!['sales','admin'].includes(role))throw Error('无效角色');const s=load();s.role=role;write(s);}
function login(loggedIn){const s=load();s.loggedIn=loggedIn;write(s);}
function reset(){write(initial());}
function notices(){const s=load();return projects().filter(p=>p.status==='待签约'||p.unpaid>0).map(p=>({id:p.id,projectId:p.id,title:p.status==='待签约'?'合同待确认':'回款跟进提醒',name:p.name,read:s.read.includes(p.id)}));}
function markRead(id){const s=load();if(!notices().some(n=>n.id===id))throw Error('无权访问此通知');s.read=Array.from(new Set(s.read.concat(id)));write(s);}
function customerList(query=''){const rows=projects();return customers.filter(c=>rows.some(p=>p.customerId===c.id)&&c.name.includes(query.trim())).map(c=>Object.assign({},c,{count:rows.filter(p=>p.customerId===c.id).length}));}
module.exports={initial,load,money,progress,projects,project,summary,save,setRole,login,reset,notices,markRead,customerList,customers};

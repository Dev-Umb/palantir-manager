import fs from 'node:fs';

const baseUrl = process.env.BASE_URL ?? 'https://palantir.umb.ink';
const password = process.env.TEST_PASSWORD ?? 'password123';
const runId = `REG-${new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14)}`;
const reportPath = `docs/public-regression-${runId}.md`;

const accounts = {
  admin: 'admin@xyc.test',
  business: 'business@xyc.test',
  engineering: 'engineering@xyc.test',
  production: 'production@xyc.test',
  procurement: 'procurement@xyc.test',
  warehouse: 'warehouse@xyc.test',
  finance: 'finance@xyc.test',
};

const steps = [];
const issues = [];
const evidence = {};

function mark(flow, actor, item, status, detail = '') {
  steps.push({ flow, actor, item, status, detail });
  console.log(`${status} ${flow} / ${actor} / ${item}${detail ? ` - ${detail}` : ''}`);
}

function issue(id, severity, flow, detail) {
  issues.push({ id, severity, flow, detail });
  console.log(`ISSUE ${id}: ${detail}`);
}

function splitSetCookie(value) {
  if (!value) return [];
  return value.split(/,(?=\s*[^;,]+=)/g);
}

function decodeHtml(value) {
  return value
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>');
}

function formBody(data, prefix = '') {
  const params = new URLSearchParams();
  const append = (value, key) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      for (const [childKey, childValue] of Object.entries(value)) {
        append(childValue, `${key}[${childKey}]`);
      }
      return;
    }

    params.append(key, value ?? '');
  };

  for (const [key, value] of Object.entries(data)) {
    append(value, prefix ? `${prefix}[${key}]` : key);
  }

  return params;
}

class Client {
  constructor(name) {
    this.name = name;
    this.cookies = new Map();
  }

  cookieHeader() {
    return [...this.cookies].map(([key, value]) => `${key}=${value}`).join('; ');
  }

  csrf() {
    const token = this.cookies.get('XSRF-TOKEN');
    return token ? decodeURIComponent(token) : '';
  }

  rememberCookies(headers) {
    const raw = typeof headers.getSetCookie === 'function'
      ? headers.getSetCookie()
      : splitSetCookie(headers.get('set-cookie'));

    for (const line of raw) {
      const [pair] = line.split(';');
      const index = pair.indexOf('=');
      if (index > 0) {
        this.cookies.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
      }
    }
  }

  async request(method, path, data = null, inertia = false) {
    const headers = {
      Accept: inertia ? 'application/json, text/html' : 'text/html,application/xhtml+xml,application/json',
      Cookie: this.cookieHeader(),
    };

    let body;
    if (data) {
      body = formBody(data);
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      headers['X-XSRF-TOKEN'] = this.csrf();
    }

    if (inertia) headers['X-Inertia'] = 'true';

    const response = await fetch(`${baseUrl}${path}`, {
      method,
      headers,
      body,
      redirect: 'manual',
    });
    this.rememberCookies(response.headers);

    const text = await response.text();
    return { response, text };
  }

  async page(path) {
    const direct = await this.request('GET', path, null, true);
    const type = direct.response.headers.get('content-type') ?? '';
    if (direct.response.status === 200 && type.includes('application/json')) {
      return JSON.parse(direct.text);
    }

    const html = direct.response.status === 409
      ? await this.request('GET', path)
      : direct;
    if (html.text.trim().startsWith('{')) {
      return JSON.parse(html.text);
    }

    const scriptMatch = html.text.match(/<script[^>]+data-page="app"[^>]*>([\s\S]*?)<\/script>/);
    if (scriptMatch) {
      return JSON.parse(scriptMatch[1]);
    }

    const match = html.text.match(/data-page="(\{[^"]+\})"/s)
      ?? html.text.match(/<div[^>]+id="app"[^>]+data-page="([^"]+)"/s);
    if (!match) {
      const debugPath = `docs/debug-${runId}-${path.replaceAll('/', '_')}.html`;
      fs.writeFileSync(debugPath, html.text);
      throw new Error(`No Inertia page found at ${path}, status ${html.response.status}; saved ${debugPath}`);
    }

    return JSON.parse(decodeHtml(match[1]));
  }

  async login(email) {
    await this.request('GET', '/login');
    const result = await this.request('POST', '/login', { email, password });
    if (![302, 303].includes(result.response.status)) {
      throw new Error(`Login failed for ${email}: ${result.response.status}`);
    }
  }
}

async function login(actor) {
  const client = new Client(actor);
  await client.login(accounts[actor]);
  mark('基础', actor, '公网登录', 'PASS', accounts[actor]);
  return client;
}

async function objectPage(client, key) {
  return client.page(`/objects/${key}`);
}

function findRecord(page, predicate) {
  return page.props.records.data.find(predicate);
}

async function createObject(client, actor, key, payload, match, flow) {
  const page = await objectPage(client, key);
  if (!page.props.can.create) throw new Error(`${actor} cannot create ${key}`);

  const result = await client.request('POST', `/objects/${page.props.currentObject.id}`, { payload });
  if (![302, 303].includes(result.response.status)) {
    throw new Error(`Create ${key} failed: ${result.response.status} ${result.text.slice(0, 120)}`);
  }

  const refreshed = await objectPage(client, key);
  const record = findRecord(refreshed, match);
  if (!record) throw new Error(`Created ${key} not visible for ${actor}`);

  mark(flow, actor, `创建 ${page.props.currentObject.label}`, 'PASS', `${record.code} ${record.title}`);
  return record;
}

async function updateRecord(client, actor, record, patch, flow, label) {
  const payload = { ...record.payload, ...patch };
  const result = await client.request('PUT', `/records/${record.id}`, { payload });
  if (![302, 303].includes(result.response.status)) {
    throw new Error(`Update ${label} failed: ${result.response.status} ${result.text.slice(0, 120)}`);
  }
  mark(flow, actor, label, 'PASS');
  return { ...record, payload };
}

async function main() {
  evidence.runId = runId;
  evidence.baseUrl = baseUrl;

  const business = await login('business');
  const engineering = await login('engineering');
  const production = await login('production');
  const procurement = await login('procurement');
  const warehouse = await login('warehouse');
  const finance = await login('finance');
  const publicClient = new Client('public');

  const customer = await createObject(business, 'business', 'customer', {
    name: `${runId} 客户`,
    contact: '回归测试',
    phone: '13800000000',
    level: 'A',
    address: '公网回归地址',
  }, (record) => record.payload.name === `${runId} 客户`, '项目链路');

  let project = await createObject(business, 'business', 'project', {
    name: `${runId} 项目`,
    project_no: runId,
    customer_id: customer.id,
    stage: '合同录入',
    overall_status: '进行中',
    delivery_date: '2026-08-30',
    owner_role: '业务',
    manager: '业务员',
    contract_qty: 12,
    weight: 36,
    risk: '公网回归测试',
  }, (record) => record.payload.project_no === runId, '项目链路');

  const contract = await createObject(business, 'business', 'contract', {
    ctype: '销售合同',
    amount: 528000,
    customer_id: customer.id,
    project_id: project.id,
    status: '已收到',
  }, (record) => record.payload.project_id === project.id && Number(record.payload.amount) === 528000, '项目链路');
  evidence.contractCode = contract.code;

  const projectAfterContract = await objectPage(business, 'project');
  project = findRecord(projectAfterContract, (record) => record.id === project.id);
  if (Number(project.payload.contract_amount) === 528000) {
    mark('项目链路', 'business', '合同金额同步项目主档', 'PASS', String(project.payload.contract_amount));
  } else {
    mark('项目链路', 'business', '合同金额同步项目主档', 'FAIL', `实际 ${project.payload.contract_amount}`);
    issue('P1', '高', '项目链路', '合同创建后项目主档合同金额未正确同步。');
  }

  project = await updateRecord(business, 'business', project, { stage: '技术确认', owner_role: '技术' }, '项目链路', '项目流转到技术确认');

  const drawing = await createObject(engineering, 'engineering', 'drawing', {
    name: `${runId} 深化图`,
    drawing_no: `${runId}-DRW`,
    project_id: project.id,
    designer: '技术员',
    release_status: '未下放',
    weight: 18,
  }, (record) => record.payload.drawing_no === `${runId}-DRW`, '项目链路');
  await updateRecord(engineering, 'engineering', drawing, { release_status: '已下放' }, '项目链路', '图纸下放');
  if ((await objectPage(engineering, 'drawing')).props.currentObject.fields.some((field) => field.key === 'attachment')) {
    mark('项目链路', 'engineering', '图纸附件字段', 'PASS');
  }

  project = await updateRecord(business, 'business', project, { stage: '生产加工', owner_role: '生产' }, '项目链路', '项目流转到生产加工');

  const workOrder = await createObject(production, 'production', 'work_order', {
    project_id: project.id,
    drawing_id: drawing.id,
    team: '班组A',
    status: '部分完成',
    progress: 45,
  }, (record) => record.payload.project_id === project.id && record.payload.drawing_id === drawing.id, '项目链路');

  await createObject(production, 'production', 'team_log', {
    work_order_id: workOrder.id,
    part_name: `${runId} 箱型梁`,
    team: '班组A',
    real_qty: 6,
    work_date: '2026-07-07',
  }, (record) => record.payload.work_order_id === workOrder.id, '项目链路');

  await publicClient.request('GET', '/team-log');
  const publicTeamLog = await publicClient.request('POST', '/team-log', {
    work_order_id: workOrder.id,
    part_name: `${runId} 公开日报`,
    team: '班组A',
    real_qty: 4,
    work_date: '2026-07-07',
  });
  if (![302, 303].includes(publicTeamLog.response.status)) {
    throw new Error(`Public team log failed: ${publicTeamLog.response.status}`);
  }
  const teamLogPage = await objectPage(production, 'team_log');
  if (!findRecord(teamLogPage, (record) => record.payload.part_name === `${runId} 公开日报`)) {
    throw new Error('Public team log not found');
  }
  mark('项目链路', 'public', '公开班组日报提交', 'PASS');

  await updateRecord(production, 'production', workOrder, { status: '完成', progress: 100 }, '项目链路', '生产任务完成');

  await createObject(production, 'production', 'shipment', {
    project_id: project.id,
    product_name: `${runId} 成品`,
    qty_ton: 18,
    ship_date: '2026-07-08',
    sign_status: '已签收',
  }, (record) => record.payload.project_id === project.id && record.payload.product_name === `${runId} 成品`, '项目链路');
  if ((await objectPage(production, 'shipment')).props.currentObject.fields.some((field) => field.key === 'attachment')) {
    mark('项目链路', 'production', '发货附件字段', 'PASS');
  }

  project = await updateRecord(business, 'business', project, { stage: '对账回款', owner_role: '财务' }, '项目链路', '项目流转到对账回款');

  await createObject(finance, 'finance', 'receivable', {
    customer_id: customer.id,
    project_id: project.id,
    contract_amount: 528000,
    invoice_amount: 528000,
    paid_amount: 528000,
    unpaid: 0,
    pay_status: '已回款',
  }, (record) => record.payload.project_id === project.id && Number(record.payload.paid_amount) === 528000, '项目链路');

  await createObject(finance, 'finance', 'invoice', {
    customer_id: customer.id,
    project_id: project.id,
    invoice_no: `${runId}-FP`,
    amount: 528000,
    invoice_date: '2026-07-08',
    status: '已开票',
  }, (record) => record.payload.project_id === project.id && Number(record.payload.amount) === 528000, '项目链路');

  const projectAfterInvoice = await objectPage(finance, 'project');
  project = findRecord(projectAfterInvoice, (record) => record.id === project.id);
  if (project && Number(project.payload.invoiced_amount) === 528000 && Number(project.payload.uninvoiced_amount) === 0) {
    mark('项目链路', 'finance', '开票金额同步项目主档', 'PASS', String(project.payload.invoiced_amount));
  } else {
    mark('项目链路', 'finance', '开票金额同步项目主档', 'FAIL', `实际 ${project?.payload?.invoiced_amount ?? '未找到项目'}`);
    issue('P3', '中', '项目链路', '开票记录未正确回写项目主档已开票金额。');
  }

  await updateRecord(finance, 'finance', project, {
    stage: '项目完成',
    overall_status: '已完成',
    paid_amount: 528000,
    arrears: 0,
    signed_qty: 18,
    shipped_qty: 18,
  }, '项目链路', '项目完成');

  const material = await createObject(warehouse, 'warehouse', 'material', {
    name: `${runId} Q355B钢板`,
    material_type: '钢板',
    spec: '12mm',
    unit: '张',
    unit_weight_type: '每平米',
    unit_weight: 94.2,
    fixed_size: '2000x8000',
    remark: runId,
    status: '启用',
  }, (record) => record.payload.name === `${runId} Q355B钢板`, '采购/库存链路');

  await production.request('GET', '/requests/create');
  const requestResult = await production.request('POST', '/requests', {
    requester: '生产',
    material_id: material.id,
    qty: 8,
    unit: '张',
    project_id: project.id,
    urgency: '紧急',
    reason: `${runId} 生产缺料`,
  });
  if (![302, 303].includes(requestResult.response.status)) {
    throw new Error(`Submit requisition failed: ${requestResult.response.status}`);
  }
  mark('采购/库存链路', 'production', '提交采购申请', 'PASS');

  const ownRequests = await objectPage(production, 'requisition');
  const requisition = findRecord(ownRequests, (record) => record.payload.reason === `${runId} 生产缺料`);
  if (!requisition) throw new Error('Submitted requisition not visible for requester');
  mark('采购/库存链路', 'production', '查看本人采购申请流转状态', 'PASS', requisition.payload.status);

  const approvals = await procurement.page('/procurement/approvals');
  const pending = approvals.props.pending.find((record) => record.id === requisition.id);
  if (!pending) throw new Error('Requisition not visible in procurement approvals');
  const approveResult = await procurement.request('POST', `/requests/${requisition.id}/approve`, {});
  if (![302, 303].includes(approveResult.response.status)) {
    throw new Error(`Approve requisition failed: ${approveResult.response.status}`);
  }
  mark('采购/库存链路', 'procurement', 'OA审批通过', 'PASS', requisition.code);

  const purchasePage = await objectPage(procurement, 'purchase');
  let purchase = findRecord(purchasePage, (record) => record.payload.material_id === material.id && Number(record.payload.qty) === 8);
  if (!purchase) throw new Error('Approved purchase daily record not found');
  mark('采购/库存链路', 'procurement', '采购日报生成', 'PASS', purchase.code);

  purchase = await updateRecord(procurement, 'procurement', purchase, { price: '4350元/吨', daily_status: '部分采购', arrived: '部分到货' }, '采购/库存链路', '采购日报部分到货');
  purchase = await updateRecord(procurement, 'procurement', purchase, { daily_status: '已采购', arrived: '已到货' }, '采购/库存链路', '采购完成');
  const purchaseAfterCompletionPage = await objectPage(procurement, 'purchase');
  purchase = findRecord(purchaseAfterCompletionPage, (record) => record.id === purchase.id);
  const purchaseFields = purchaseAfterCompletionPage.props.currentObject.fields;
  if (purchaseFields.length === 18 && purchase?.payload.actual_arrival_date && !purchaseFields.some((field) => ['completed_by', 'acceptance_attachment'].includes(field.key))) {
    mark('采购/库存链路', 'procurement', '采购字段对齐并记录到货', 'PASS', purchase.payload.actual_arrival_date);
  } else {
    mark('采购/库存链路', 'procurement', '采购字段对齐并记录到货', 'FAIL');
    issue('S3', '中', '采购/库存链路', '采购日报字段未按飞书18列对齐。');
  }

  await createObject(warehouse, 'warehouse', 'inbound', {
    material_id: material.id,
    qty: 8,
    weight: 753.6,
    bin: 'A区-回归',
    in_date: '2026-07-08',
  }, (record) => record.payload.material_id === material.id && Number(record.payload.qty) === 8, '采购/库存链路');

  await publicClient.request('GET', '/material-request');
  const materialRequestResult = await publicClient.request('POST', '/material-request', {
    requester: '下料班组',
    material_id: material.id,
    project_id: project.id,
    qty: 3,
    unit: '张',
    team: '下料班组',
    apply_date: '2026-07-09',
    reason: `${runId} 公开领料`,
  });
  if (![302, 303].includes(materialRequestResult.response.status)) {
    throw new Error(`Public material request failed: ${materialRequestResult.response.status}`);
  }
  mark('采购/库存链路', 'public', '公开领料申请提交', 'PASS');

  const materialApprovals = await warehouse.page('/warehouse/material-requests');
  const materialRequest = materialApprovals.props.pending.find((record) => record.payload.reason === `${runId} 公开领料`);
  if (!materialRequest) throw new Error('Public material request not visible in warehouse approvals');
  const materialApproveResult = await warehouse.request('POST', `/material-requests/${materialRequest.id}/approve`, {});
  if (![302, 303].includes(materialApproveResult.response.status)) {
    throw new Error(`Approve material request failed: ${materialApproveResult.response.status}`);
  }
  mark('采购/库存链路', 'warehouse', '领料审批并生成出库单', 'PASS', materialRequest.code);

  const outboundPage = await objectPage(warehouse, 'outbound');
  if (!findRecord(outboundPage, (record) => record.payload.material_id === material.id && Number(record.payload.qty) === 3)) {
    throw new Error('Outbound generated by material request not found');
  }

  const ledgerAfterOutboundPage = await objectPage(warehouse, 'stock_ledger');
  let ledger = findRecord(ledgerAfterOutboundPage, (record) => record.payload.material_id === material.id);
  if (ledger && Number(ledger.payload.in_qty) === 8 && Number(ledger.payload.out_qty) === 3 && Number(ledger.payload.balance) === 5) {
    mark('采购/库存链路', 'warehouse', '库存台账自动扣减', 'PASS', `结存 ${ledger.payload.balance}`);
  } else {
    mark('采购/库存链路', 'warehouse', '库存台账自动扣减', 'FAIL', `实际 ${ledger?.payload?.balance ?? '未找到台账'}`);
    issue('S2', '中', '采购/库存链路', '入库、出库后库存台账未自动计算。');
  }

  await createObject(warehouse, 'warehouse', 'scrap_ledger', {
    scrap_date: '2026-07-09',
    material_category: '钢板',
    spec: '12mm',
    qty: 0.2,
    loss_rate: 0.03,
    laser_team: '班组A',
    outbound_total: 3,
    raw_weight: 282.6,
    scrap_weight: 8.5,
    unit: '张',
  }, (record) => Number(record.payload.outbound_total) === 3 && Number(record.payload.loss_rate) === 0.03, '采购/库存链路');

  await createObject(warehouse, 'warehouse', 'stocktake', {
    material_id: material.id,
    book_qty: 5,
    real_qty: 5,
    diff_reason: '账实一致',
    handle_status: '已完成',
  }, (record) => record.payload.material_id === material.id && record.payload.handle_status === '已完成', '采购/库存链路');
  const ledgerAfterStocktakePage = await objectPage(warehouse, 'stock_ledger');
  ledger = findRecord(ledgerAfterStocktakePage, (record) => record.payload.material_id === material.id);
  if (ledger && Number(ledger.payload.balance) === 5) {
    mark('采购/库存链路', 'warehouse', '盘点回写库存台账', 'PASS', `结存 ${ledger.payload.balance}`);
  } else {
    mark('采购/库存链路', 'warehouse', '盘点回写库存台账', 'FAIL', `实际 ${ledger?.payload?.balance ?? '未找到台账'}`);
    issue('S2', '中', '采购/库存链路', '盘点后库存台账未自动更新。');
  }

  const report = [
    `# 公网全链路回归测试报告 - ${runId}`,
    '',
    `- 测试入口：${baseUrl}`,
    `- 测试时间：${new Date().toISOString()}`,
    `- 测试账号：${Object.entries(accounts).map(([role, email]) => `${role}=${email}`).join('；')}`,
    '',
    '## 结论',
    '',
    `- 通过步骤：${steps.filter((step) => step.status === 'PASS').length}`,
    `- 失败步骤：${steps.filter((step) => step.status === 'FAIL').length}`,
    `- 记录问题：${issues.length}`,
    '',
    '## 测试步骤记录',
    '',
    '| 链路 | 角色 | 步骤 | 结果 | 记录 |',
    '| --- | --- | --- | --- | --- |',
    ...steps.map((step) => `| ${step.flow} | ${step.actor} | ${step.item} | ${step.status} | ${step.detail.replaceAll('|', '/')} |`),
    '',
    '## 问题清单',
    '',
    '| 编号 | 严重级别 | 链路 | 问题 |',
    '| --- | --- | --- | --- |',
    ...issues.map((item) => `| ${item.id} | ${item.severity} | ${item.flow} | ${item.detail.replaceAll('|', '/')} |`),
    '',
    '## 测试数据索引',
    '',
    `- Run ID：${runId}`,
    `- 客户：${customer.code}`,
    `- 项目：${project.code}`,
    `- 合同：${contract.code}`,
    `- 图纸：${drawing.code}`,
    `- 采购申请：${requisition.code}`,
    `- 采购日报：${purchase.code}`,
    `- 物料：${material.code}`,
    '',
  ].join('\n');

  fs.writeFileSync(reportPath, report);
  console.log(`REPORT ${reportPath}`);
}

main().catch((error) => {
  mark('执行器', 'system', '回归脚本异常', 'FAIL', error.message);
  const report = [
    `# 公网全链路回归测试报告 - ${runId}`,
    '',
    `- 测试入口：${baseUrl}`,
    `- 测试时间：${new Date().toISOString()}`,
    '',
    '## 已执行步骤',
    '',
    '| 链路 | 角色 | 步骤 | 结果 | 记录 |',
    '| --- | --- | --- | --- | --- |',
    ...steps.map((step) => `| ${step.flow} | ${step.actor} | ${step.item} | ${step.status} | ${step.detail.replaceAll('|', '/')} |`),
    '',
    '## 脚本异常',
    '',
    error.stack,
    '',
  ].join('\n');
  fs.writeFileSync(reportPath, report);
  console.error(error);
  console.log(`REPORT ${reportPath}`);
  process.exit(1);
});

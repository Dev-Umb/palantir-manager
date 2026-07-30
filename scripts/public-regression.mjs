import fs from 'node:fs';
import path from 'node:path';
import { loadOnlineRegressionConfig } from './online-regression-config.mjs';

const {
  baseUrl,
  password,
  runId,
  reportPath,
} = loadOnlineRegressionConfig(process.env);
const today = new Date().toISOString().slice(0, 10);
const publicTeamLogUrl = process.env.PUBLIC_TEAM_LOG_URL ?? '';

const accounts = {
  admin: 'admin@xyc.test',
  business: 'business@xyc.test',
  engineering: 'engineering@xyc.test',
  procurement: 'procurement@xyc.test',
  production_manager: 'production_manager@xyc.test',
  production: 'production@xyc.test',
  finance: 'finance@xyc.test',
};

const steps = [];
const issues = [];
const evidence = {};

function mark(flow, actor, item, status, detail = '') {
  steps.push({ flow, actor, item, status, detail });
  console.log(`${status} ${flow} / ${actor} / ${item}${detail ? ` - ${detail}` : ''}`);
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
    if (Array.isArray(value)) {
      value.forEach((child, index) => append(child, `${key}[${index}]`));
      return;
    }

    if (value && typeof value === 'object') {
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

function saveReport(content) {
  fs.mkdirSync(path.dirname(reportPath), { recursive: true });
  fs.writeFileSync(reportPath, content);
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

  async request(method, requestPath, data = null, inertia = false) {
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

    const response = await fetch(`${baseUrl}${requestPath}`, {
      method,
      headers,
      body,
      redirect: 'manual',
    });
    this.rememberCookies(response.headers);

    return { response, text: await response.text() };
  }

  async page(requestPath) {
    const direct = await this.request('GET', requestPath, null, true);
    const type = direct.response.headers.get('content-type') ?? '';
    if (direct.response.status === 200 && type.includes('application/json')) {
      return JSON.parse(direct.text);
    }

    const html = direct.response.status === 409
      ? await this.request('GET', requestPath)
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
      throw new Error(`No Inertia page found at ${requestPath}, status ${html.response.status}`);
    }

    return JSON.parse(decodeHtml(match[1]));
  }

  async login(email) {
    await this.request('GET', '/login');
    const result = await this.request('POST', '/login', { email, password });
    if (![302, 303].includes(result.response.status)) {
      throw new Error(`Login failed for ${email}: ${result.response.status}`);
    }

    const authenticated = await this.request('GET', '/');
    if (authenticated.response.status !== 200) {
      throw new Error(`Login did not create an authenticated session for ${email}: ${authenticated.response.status}`);
    }
  }
}

async function login(actor) {
  const client = new Client(actor);
  await client.login(accounts[actor]);
  mark('身份与权限', actor, '登录', 'PASS', accounts[actor]);
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
    throw new Error(`Create ${key} failed: ${result.response.status} ${result.text.slice(0, 180)}`);
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
    throw new Error(`Update ${label} failed: ${result.response.status} ${result.text.slice(0, 180)}`);
  }

  mark(flow, actor, label, 'PASS');
  return { ...record, payload };
}

async function createCustomerContact(client, customer) {
  const customerPage = await objectPage(client, 'customer');
  const contactObject = customerPage.props.objects.find((object) => object.key === 'customer_contact');
  if (!contactObject) throw new Error('Customer contact object is unavailable to business');

  const result = await client.request('POST', `/objects/${contactObject.id}`, {
    payload: {
      name: `${runId} 联系人`,
      phone: '13800000000',
      customer_id: customer.id,
    },
  });
  if (![302, 303].includes(result.response.status)) {
    throw new Error(`Create customer contact failed: ${result.response.status}`);
  }

  const refreshed = await objectPage(client, 'customer');
  const refreshedCustomer = findRecord(refreshed, (record) => record.id === customer.id);
  const contact = refreshedCustomer?.contacts?.find((item) => item.name === `${runId} 联系人`);
  if (!contact) throw new Error('Created customer contact is not embedded in customer information');

  mark('客户与项目', 'business', '在客户信息内新增联系人', 'PASS', contact.name);
  return contact;
}

async function expectStatus(client, actor, requestPath, expected, label) {
  const result = await client.request('GET', requestPath);
  if (result.response.status !== expected) {
    throw new Error(`${actor} ${label}: expected ${expected}, got ${result.response.status}`);
  }

  mark('身份与权限', actor, label, 'PASS', `HTTP ${expected}`);
}

async function main() {
  evidence.runId = runId;
  evidence.baseUrl = baseUrl;

  const admin = await login('admin');
  const business = await login('business');
  const engineering = await login('engineering');
  const procurement = await login('procurement');
  const productionManager = await login('production_manager');
  const production = await login('production');
  const finance = await login('finance');
  const publicClient = new Client('public');

  await expectStatus(admin, 'admin', '/admin/rbac', 200, '访问用户与权限');
  await expectStatus(engineering, 'engineering', '/objects/project', 403, '拒绝访问项目主档');
  await expectStatus(business, 'business', '/objects/drawing', 403, '拒绝访问技术图纸');
  await expectStatus(production, 'production', '/objects/material', 403, '拒绝访问物料主档');
  await expectStatus(procurement, 'procurement', '/objects/material', 200, '访问物料主档');
  await expectStatus(finance, 'finance', '/objects/invoice', 200, '访问开票记录');
  await expectStatus(publicClient, 'public', '/objects/project', 302, '未登录访问受保护对象跳转登录');

  const customer = await createObject(business, 'business', 'customer', {
    name: `${runId} 客户`,
    level: 'A',
    address: '全链路回归地址',
    cooperation_history: '自动化回归客户',
    remark: runId,
  }, (record) => record.payload.name === `${runId} 客户`, '客户与项目');

  const hiddenContactRoute = await business.request('GET', '/objects/customer_contact');
  if (hiddenContactRoute.response.status !== 302
    || !hiddenContactRoute.response.headers.get('location')?.endsWith('/objects/customer')) {
    throw new Error('Customer contact standalone route did not redirect to customer information');
  }
  mark('客户与项目', 'business', '联系人独立入口重定向客户信息', 'PASS');
  const contact = await createCustomerContact(business, customer);

  let project = await createObject(business, 'business', 'project', {
    name: `${runId} 项目`,
    customer_contact_ids: [contact.id],
    customer_id: customer.id,
    stage: '合同录入',
    overall_status: '进行中',
    delivery_date: today,
    owner_role: '业务',
    handover_date: today,
    manager: '回归业务员',
    weight: 36,
    risk: runId,
  }, (record) => record.payload.name === `${runId} 项目`, '客户与项目');

  let contract = await createObject(business, 'business', 'contract', {
    customer_id: customer.id,
    project_id: project.id,
    status: '未收到',
    ctype: '销售合同',
    amount: 528000,
    signed_date: today,
    business_owner: '回归业务员',
    contract_qty: 12,
    remark: runId,
  }, (record) => record.payload.project_id === project.id, '合同与工作流');
  contract = await updateRecord(
    business,
    'business',
    contract,
    { status: '已收到' },
    '合同与工作流',
    '合同确认并触发技术/财务任务',
  );

  let projectPage = await objectPage(business, 'project');
  project = findRecord(projectPage, (record) => record.id === project.id);
  if (project.payload.stage !== '技术确认' || Number(project.payload.contract_amount) !== 528000) {
    throw new Error(`Contract workflow did not update project: ${JSON.stringify(project.payload)}`);
  }
  mark('合同与工作流', 'business', '项目阶段与合同金额同步', 'PASS', project.payload.stage);

  const drawingPage = await objectPage(engineering, 'drawing');
  let drawing = findRecord(drawingPage, (record) => record.payload.project_id === project.id);
  if (!drawing) throw new Error('Workflow drawing not visible for engineering');
  drawing = await updateRecord(engineering, 'engineering', drawing, {
    name: `${runId} 深化图`,
    designer: '回归技术员',
    drawing_date: today,
    design_status: '已下放',
    project_progress: '已下放生产',
    weight: 18,
  }, '技术与生产', '图纸下放并触发生产任务');

  const teamPage = await objectPage(productionManager, 'production_team');
  const productionTeam = findRecord(teamPage, (record) => (record.payload.status ?? '启用') !== '停用');
  if (!productionTeam) throw new Error('No active production team available');

  const memberPage = await objectPage(productionManager, 'team_member');
  const productionMember = findRecord(memberPage, (record) => record.payload.team_id === productionTeam.id
    && (record.payload.status ?? '启用') !== '停用');
  if (!productionMember) throw new Error('No active production member available');

  const workOrderPage = await objectPage(productionManager, 'work_order');
  let workOrder = findRecord(workOrderPage, (record) => record.payload.drawing_id === drawing.id);
  if (!workOrder) throw new Error('Workflow work order not visible for production manager');
  workOrder = await updateRecord(productionManager, 'production_manager', workOrder, {
    status: '生产中',
    task_type: '钢结构加工',
    expected_material: 'Q355B',
    team_id: productionTeam.id,
    plan_start_date: today,
    actual_start_date: today,
    material_ready_status: '已到位',
    release_status: '已下放',
    production_owner_id: productionMember.id,
    weight: 18,
    production_qty_ton: 9,
    expected_finish_date: today,
  }, '技术与生产', '生产任务接单');

  await createObject(productionManager, 'production_manager', 'teardown', {
    drawing_id: drawing.id,
    teardown_date: today,
    teardown_finished_at: today,
    operator: '回归拆解组',
    material_ready_status: '已到位',
    plan_start_date: today,
    actual_start_date: today,
    remark: runId,
  }, (record) => record.payload.drawing_id === drawing.id, '技术与生产');

  await createObject(production, 'production', 'team_log', {
    project_id: project.id,
    team_id: productionTeam.id,
    status: '生产中',
    process: '焊接',
    completed_qty: 9,
    unit: '吨',
    exception_type: '无',
    part_name: `${runId} 箱型梁`,
    work_date: today,
    remark: runId,
  }, (record) => record.payload.project_id === project.id
    && record.payload.part_name === `${runId} 箱型梁`, '技术与生产');

  if (!publicTeamLogUrl) {
    throw new Error('PUBLIC_TEAM_LOG_URL is required for signed public team-log coverage');
  }
  const signedTeamLog = new URL(publicTeamLogUrl);
  const signedTeamLogPath = `${signedTeamLog.pathname}${signedTeamLog.search}`;
  await publicClient.request('GET', signedTeamLogPath);
  const publicTeamLog = await publicClient.request('POST', signedTeamLogPath, {
    project_id: project.id,
    team_id: productionTeam.id,
    status: '生产中',
    process: '焊接',
    completed_qty: 1,
    unit: '吨',
    exception_type: '无',
    part_name: `${runId} 公开报工`,
    work_date: today,
    remark: runId,
  });
  if (![302, 303].includes(publicTeamLog.response.status)) {
    throw new Error(`Public team log failed: ${publicTeamLog.response.status}`);
  }
  mark('技术与生产', 'public', '公开现场报工', 'PASS');

  workOrder = await updateRecord(
    productionManager,
    'production_manager',
    workOrder,
    { status: '已完成', production_qty_ton: 18 },
    '技术与生产',
    '生产任务完成并触发发货任务',
  );

  const shipmentPage = await objectPage(productionManager, 'shipment');
  let shipment = findRecord(shipmentPage, (record) => record.payload.project_id === project.id);
  if (!shipment) throw new Error('Workflow shipment not visible for production manager');
  shipment = await updateRecord(productionManager, 'production_manager', shipment, {
    product_name: `${runId} 成品`,
    qty_ton: 18,
    ship_date: today,
    shipping_owner: '回归生产负责人',
    logistics_info: '全链路回归运输',
    plate_no: '苏E·TEST',
    driver_phone: '13800000000',
  }, '发货与财务', '成品发货并推进项目阶段');

  project = findRecord(await objectPage(business, 'project'), (record) => record.id === project.id);
  project = await updateRecord(
    business,
    'business',
    project,
    { stage: '对账回款', owner_role: '财务' },
    '发货与财务',
    '项目移交财务',
  );

  const receivablePage = await objectPage(finance, 'receivable');
  let receivable = findRecord(receivablePage, (record) => record.payload.project_id === project.id);
  if (!receivable) throw new Error('Workflow receivable not visible for finance');
  receivable = await updateRecord(finance, 'finance', receivable, {
    pay_status: '已回款',
    signed_weight: 18,
    occurred_amount: 528000,
    occurred_amount_updated_at: today,
    paid_amount: 528000,
    reconciled_amount: 528000,
    reconcile_date: today,
    last_payment_date: today,
  }, '发货与财务', '项目财务台账完成回款');

  const invoice = await createObject(finance, 'finance', 'invoice', {
    customer_id: customer.id,
    project_id: project.id,
    invoice_no: `${runId}-FP`,
    amount: 528000,
    invoice_date: today,
    status: '已开票',
  }, (record) => record.payload.project_id === project.id
    && record.payload.invoice_no === `${runId}-FP`, '发货与财务');

  receivable = await updateRecord(finance, 'finance', receivable, {
    invoiced_amount: 528000,
  }, '发货与财务', '项目财务台账登记开票金额');

  projectPage = await objectPage(business, 'project');
  project = findRecord(projectPage, (record) => record.id === project.id);
  if (Number(project.payload.paid_amount) !== 528000
    || Number(project.payload.invoiced_amount) !== 528000) {
    throw new Error(`Finance did not sync to project: ${JSON.stringify(project.payload)}`);
  }
  mark('发货与财务', 'business', '回款与开票金额回写项目', 'PASS');
  project = await updateRecord(business, 'business', project, {
    stage: '项目完成',
    overall_status: '已完成',
  }, '发货与财务', '项目完成');

  const material = await createObject(procurement, 'procurement', 'material', {
    name: `${runId} Q355B钢板`,
    spec: '12mm',
    length_mm: 12000,
    width_mm: 2200,
    status: '启用',
    unit_weight_type: '每张',
    unit_weight: 3.3,
    remark: runId,
  }, (record) => record.payload.name === `${runId} Q355B钢板`, '采购链路');

  await production.request('GET', '/requests/create');
  const requestResult = await production.request('POST', '/requests', {
    requester: '生产',
    material_id: material.id,
    qty: 8,
    unit: '张',
    project_id: project.id,
    urgency: '紧急',
    reason: `${runId} 生产补料`,
  });
  if (![302, 303].includes(requestResult.response.status)) {
    throw new Error(`Submit requisition failed: ${requestResult.response.status}`);
  }
  mark('采购链路', 'production', '提交采购申请', 'PASS');

  const requisitionPage = await objectPage(production, 'requisition');
  const requisition = findRecord(requisitionPage, (record) => record.payload.reason === `${runId} 生产补料`);
  if (!requisition) throw new Error('Submitted requisition not visible for production');
  mark('采购链路', 'production', '查看本人采购申请状态', 'PASS', requisition.payload.status);

  let approvals = await procurement.page('/procurement/approvals');
  if (!approvals.props.pending.some((record) => record.id === requisition.id)) {
    throw new Error('Requisition not visible in procurement approvals');
  }
  const approveResult = await procurement.request('POST', `/requests/${requisition.id}/approve`, {});
  if (![302, 303].includes(approveResult.response.status)) {
    throw new Error(`Approve requisition failed: ${approveResult.response.status}`);
  }
  mark('采购链路', 'procurement', '采购 OA 审批通过', 'PASS', requisition.code);

  let purchasePage = await objectPage(procurement, 'purchase');
  let purchase = findRecord(purchasePage, (record) => (record.payload.items || [])
    .some((item) => item.material_id === material.id && Number(item.qty) === 8));
  if (!purchase) throw new Error('Approved purchase record not found');
  mark('采购链路', 'procurement', '自动生成采购执行', 'PASS', purchase.code);

  purchase = await updateRecord(procurement, 'procurement', purchase, {
    purchase_date: today,
    supplier_name: '全链路回归供应商',
    items: purchase.payload.items.map((item) => ({
      ...item,
      price: 4350,
      total_price: Number(item.qty) * 4350,
      arrived: '已到货',
      daily_status: '已采购',
      expected_arrival_date: today,
      actual_arrival_date: today,
      remark: runId,
    })),
  }, '采购链路', '采购完成并记录到货');

  purchasePage = await objectPage(procurement, 'purchase');
  purchase = findRecord(purchasePage, (record) => record.id === purchase.id);
  if (!purchase.payload.items.every((item) => item.daily_status === '已采购'
    && item.arrived === '已到货'
    && item.actual_arrival_date === today)) {
    throw new Error('Purchase item completion did not persist');
  }
  mark('采购链路', 'procurement', '采购明细状态持久化', 'PASS');

  await publicClient.request('GET', '/purchase-request');
  const publicRequestResult = await publicClient.request('POST', '/purchase-request', {
    requester: '业务',
    material_id: material.id,
    qty: 1,
    unit: '张',
    urgency: '普通',
    reason: `${runId} 公开采购申请`,
  });
  if (![302, 303].includes(publicRequestResult.response.status)) {
    throw new Error(`Public requisition failed: ${publicRequestResult.response.status}`);
  }
  mark('采购链路', 'public', '公开采购申请提交', 'PASS');

  approvals = await procurement.page('/procurement/approvals');
  const rejected = approvals.props.pending.find(
    (record) => record.payload.reason === `${runId} 公开采购申请`,
  );
  if (!rejected) throw new Error('Public requisition not visible in procurement approvals');
  const rejectResult = await procurement.request('POST', `/requests/${rejected.id}/reject`, {});
  if (![302, 303].includes(rejectResult.response.status)) {
    throw new Error(`Reject requisition failed: ${rejectResult.response.status}`);
  }
  mark('采购链路', 'procurement', '采购 OA 驳回', 'PASS', rejected.code);

  const adminObjects = await objectPage(admin, 'customer');
  if (adminObjects.props.objects.some((object) => object.group === '历史库存（已停用）')) {
    throw new Error('Archived inventory object leaked into visible object navigation');
  }
  mark('身份与权限', 'admin', '已停用库存对象保持隐藏', 'PASS');

  evidence.customerCode = customer.code;
  evidence.contactId = contact.id;
  evidence.projectCode = project.code;
  evidence.contractCode = contract.code;
  evidence.drawingCode = drawing.code;
  evidence.workOrderCode = workOrder.code;
  evidence.shipmentCode = shipment.code;
  evidence.invoiceCode = invoice.code;
  evidence.materialCode = material.code;
  evidence.requisitionCode = requisition.code;
  evidence.purchaseCode = purchase.code;

  const report = [
    `# 全链路回归测试报告 - ${runId}`,
    '',
    `- 测试入口：${baseUrl}`,
    `- 测试时间：${new Date().toISOString()}`,
    `- 测试账号：${Object.entries(accounts).map(([role, email]) => `${role}=${email}`).join('；')}`,
    '- 范围：当前非库存业务链路、公开入口及现役角色权限',
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
    '## 测试数据索引',
    '',
    ...Object.entries(evidence).map(([key, value]) => `- ${key}：${value}`),
    '',
  ].join('\n');

  saveReport(report);
  console.log(`REPORT ${reportPath}`);
}

main().catch((error) => {
  mark('执行器', 'system', '回归脚本异常', 'FAIL', error.message);
  saveReport([
    `# 全链路回归测试报告 - ${runId}`,
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
  ].join('\n'));
  console.error(error);
  console.log(`REPORT ${reportPath}`);
  process.exit(1);
});

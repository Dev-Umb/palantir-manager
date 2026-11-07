export const blankRow = () => ({ id: crypto.randomUUID(), date: null, vehicle: '', name: '', unit: '吨', quantity: null, price: null, net_price: null, processing_fee: null, note: '' });
export const blankOrder = () => ({ id: crypto.randomUUID(), date: new Date().toLocaleDateString('sv-SE'), vehicle: '', source: '', rows: [] });
export function totals(orders) {
    let milli = 0, cents = 0, pending = 0; const dates = [];
    for (const order of orders) for (const row of order.rows) {
        if (!row.name?.trim() && row.quantity == null && row.price == null) continue;
        const quantity = Math.round(Number(row.quantity) * 1000);
        if (quantity > 0 && row.price != null && row.price !== '') cents += Math.round(quantity * Math.round(Number(row.price) * 100) / 1000);
        const date = Object.hasOwn(row, 'date') ? row.date : order.date;
        if (!row.name?.trim() || (row.unit || '吨') !== '吨' || !(Number(row.quantity) > 0)) { pending++; continue; }
        milli += quantity; if (date) dates.push(date);
    }
    return { tonnes: milli / 1000, amount: cents / 100, last_date: dates.sort().at(-1) || null, pending };
}
export async function request(url, body, method = 'POST') {
    const form = body instanceof FormData;
    const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', ...(!form ? { 'Content-Type': 'application/json' } : {}) }, body: form ? body : JSON.stringify(body) });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) { const error = new Error(Object.values(result.errors || {}).flat().join('；') || result.message || `保存失败（${response.status}），输入已保留`); error.status = response.status; throw error; }
    return result;
}
export const number = value => value == null ? '—' : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 3 });

export function sheetGroups(orders) {
    const groups = new Map();
    orders.forEach((order, oi) => {
        const key = order.source ? order.source.replace(/ \/ 第\d+行$/, '') : '手工录入';
        if (!groups.has(key)) groups.set(key, { key, orders: [], rows: [] });
        const group = groups.get(key); group.orders.push(order);
        order.rows.forEach((row, ri) => group.rows.push({ oi, ri, row: { ...row, date: Object.hasOwn(row, 'date') ? row.date : order.date, vehicle: Object.hasOwn(row, 'vehicle') ? row.vehicle : order.vehicle }, source: order.source }));
    });
    for (const group of groups.values()) group.rows.sort((a,b) => Number(a.source?.match(/第(\d+)行$/)?.[1] || 0) - Number(b.source?.match(/第(\d+)行$/)?.[1] || 0));
    return [...groups.values()];
}

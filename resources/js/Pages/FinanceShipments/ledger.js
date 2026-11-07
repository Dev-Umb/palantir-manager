export const blankRow = () => ({ id: crypto.randomUUID(), name: '', unit: '吨', quantity: null, price: null, note: '' });
export const blankOrder = () => ({ id: crypto.randomUUID(), date: new Date().toLocaleDateString('sv-SE'), vehicle: '', source: '', rows: [] });
export function totals(orders) {
    let milli = 0, cents = 0, pending = 0; const dates = [];
    for (const order of orders) for (const row of order.rows) {
        if (!row.name?.trim() && row.quantity == null && row.price == null) continue;
        if (!order.date || !row.name?.trim() || row.unit !== '吨' || !(Number(row.quantity) > 0)) { pending++; continue; }
        const quantity = Math.round(Number(row.quantity) * 1000);
        milli += quantity; dates.push(order.date);
        if (row.price != null && row.price !== '') cents += Math.round(quantity * Math.round(Number(row.price) * 100) / 1000);
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

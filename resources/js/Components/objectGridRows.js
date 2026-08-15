export function isItemField(field) {
    return field?.scope === 'item';
}

export function expandObjectRecords(records, fields) {
    const hasItemFields = fields.some(isItemField);

    return records.flatMap((record) => {
        const items = hasItemFields
            ? (Array.isArray(record.payload?.items) && record.payload.items.length ? record.payload.items : [{}])
            : [null];

        return items.map((item, index) => ({
            ...(record.payload || {}),
            ...(item || {}),
            id: hasItemFields ? `${record.id}:${item?.id || index}` : record.id,
            __recordId: record.id,
            __itemId: item?.id || null,
            __itemIndex: index,
            __record: record,
            _code: record.code,
            _title: record.title,
        }));
    });
}

export function sameRecordSpan({ nodeA, nodeB }) {
    const recordA = nodeA?.data?.__recordId;
    const recordB = nodeB?.data?.__recordId;

    return Boolean(recordA && recordA === recordB);
}

export function updateRecordField(record, field, value, itemId = null) {
    if (!isItemField(field)) {
        return {
            ...record,
            payload: { ...record.payload, [field.key]: value },
        };
    }

    return {
        ...record,
        payload: {
            ...record.payload,
            items: (record.payload?.items || []).map((item) => (
                item.id === itemId ? { ...item, [field.key]: value } : item
            )),
        },
    };
}

export function flatExportRows(records, fields) {
    return expandObjectRecords(records, fields).map((row) => Object.fromEntries(
        fields.map((field) => [field.key, rawRowValue(field, row)]),
    ));
}

export function rawRowValue(field, row) {
    if (!row) return '';
    if (row.__subtotal) return row.__subtotalValues?.[field.key] ?? '';
    if (field.system === 'code') return row._code;
    if (field.system === 'title') return row._title;

    return row[field.key] ?? '';
}

export function scopedRelationOptions(relationOptions = {}, payload = {}, display = {}, editingRecordId = '') {
    const contactOptions = relationOptions.customer_contact_ids;
    const withContext = Object.fromEntries(Object.entries(relationOptions).map(([key, option]) => [key, {
        ...option,
        ...(editingRecordId && option?.search_url
            ? { search_url: withQueryParameter(option.search_url, 'editing_record', editingRecordId) }
            : {}),
        search_context: {
            ...(option?.search_context || {}),
            ...(key === 'customer_contact_ids' && payload?.customer_id ? { customer_id: payload.customer_id } : {}),
            ...(['leader_id', 'production_owner_id', 'receiver_member_id'].includes(key) && payload?.team_id
                ? { team_id: payload.team_id }
                : {}),
        },
    }]));
    if (!contactOptions) return withContext;

    const allItems = contactOptions.items || [];
    const customerId = payload?.customer_id;
    const items = customerId
        ? allItems.filter((item) => item.meta?.customer_id === customerId)
        : [];
    const availableIds = new Set(items.map((item) => item.id));
    const allItemsById = new Map(allItems.map((item) => [item.id, item]));
    const selectedIds = Array.isArray(payload?.customer_contact_ids) ? payload.customer_contact_ids : [];
    const selectedLabels = Array.isArray(display?.customer_contact_ids) ? display.customer_contact_ids : [];
    const selectedItems = selectedIds.flatMap((id, index) => {
        if (availableIds.has(id)) return [];

        return [{
            id,
            label: selectedLabels[index] || allItemsById.get(id)?.label || `历史联系人 ${id}`,
        }];
    });

    return {
        ...withContext,
        customer_contact_ids: {
            ...withContext.customer_contact_ids,
            items,
            selectedItems,
        },
    };
}

function withQueryParameter(url, key, value) {
    const absolute = /^https?:\/\//i.test(url);
    const parsed = new URL(url, 'http://localhost');
    parsed.searchParams.set(key, value);

    return absolute ? parsed.toString() : `${parsed.pathname}${parsed.search}${parsed.hash}`;
}

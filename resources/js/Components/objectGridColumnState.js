export function columnOrderStorageKey(userId, objectKey) {
    return `xyc.objectGrid.columnOrder.${userId}.${objectKey}`;
}

export function columnOrderFromState(state, fieldKeys) {
    return currentKeys(state.map((item) => item.colId), fieldKeys);
}

export function columnOrderState(savedOrder, fieldKeys) {
    const ordered = currentKeys(savedOrder, fieldKeys);
    const seen = new Set(ordered);

    return [
        ...ordered,
        ...fieldKeys.filter((key) => !seen.has(key)),
    ].map((colId) => ({ colId }));
}

export function fieldsInColumnOrder(fields, savedOrder = []) {
    const byKey = new Map(fields.map((field) => [field.key, field]));
    const ordered = columnOrderState(savedOrder, fields.map((field) => field.key))
        .map(({ colId }) => byKey.get(colId))
        .filter(Boolean);

    return [
        ...ordered.filter((field) => field.scope !== 'item'),
        ...ordered.filter((field) => field.scope === 'item'),
    ];
}

export function readColumnOrder(storageKey) {
    try {
        const value = window.localStorage.getItem(storageKey);
        const parsed = value ? JSON.parse(value) : [];

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function currentKeys(keys, fieldKeys) {
    const fields = new Set(fieldKeys);
    const seen = new Set();

    return keys.filter((key) => {
        if (!fields.has(key) || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

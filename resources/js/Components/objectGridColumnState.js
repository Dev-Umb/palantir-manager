export function columnOrderStorageKey(objectKey) {
    return `xyc.objectGrid.columnOrder.${objectKey}`;
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

function currentKeys(keys, fieldKeys) {
    const fields = new Set(fieldKeys);
    const seen = new Set();

    return keys.filter((key) => {
        if (!fields.has(key) || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

export function formatObjectNumber(objectKey, field, value) {
    if (
        objectKey !== 'project'
        || field?.type !== 'number'
        || value === ''
        || value === null
        || value === undefined
        || !Number.isFinite(Number(value))
    ) {
        return value;
    }

    const formatted = Number(value).toLocaleString('zh-CN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return field.key === 'payment_progress' ? `${formatted}%` : formatted;
}

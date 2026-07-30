const exactLabels = {
    '本体工作台': '业务资料',
    '主数据': '基础资料',
    '项目主档': '项目资料',
    '物料主档': '物料资料',
    '材料主档': '材料资料',
    '对象': '资料类型',
    '业务对象': '业务资料',
};

export function businessText(value) {
    if (typeof value !== 'string' || value === '') return value;

    if (exactLabels[value]) return exactLabels[value];

    return value
        .replaceAll('本体工作台', '业务资料')
        .replaceAll('对象 CRUD', '业务资料的查看、新建、编辑和删除')
        .replaceAll('业务对象', '业务资料')
        .replaceAll('对象记录', '业务数据')
        .replaceAll('项目主档', '项目资料')
        .replaceAll('物料主档', '物料资料')
        .replaceAll('材料主档', '材料资料');
}

export function permissionGroupLabel(permissions, fallback) {
    const firstLabel = permissions?.[0]?.label;
    if (!firstLabel) return businessText(fallback);

    return businessText(firstLabel.replace(/^(查看|新建|编辑|删除|使用|管理|提交)/, '').trim());
}

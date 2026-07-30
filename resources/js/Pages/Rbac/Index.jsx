import { Head, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import Layout from '../../Components/Layout';
import { businessText, permissionGroupLabel } from '../../businessLanguage';

export default function Index({ users, roles, permissions }) {
    return (
        <Layout title="用户与权限" eyebrow="用户与权限" aside={<RbacAside />}>
            <Head title="用户与权限" />
            <section className="surface">
                <div className="section-head">
                    <div>
                        <p>注册用户</p>
                        <h2>角色配置</h2>
                    </div>
                    <span className="pill">基础角色默认仅大盘 + 提交申请</span>
                </div>
                <div className="user-list">
                    {users.map((user) => <UserRoleEditor key={user.id} user={user} roles={roles} />)}
                </div>
            </section>

            <section className="surface">
                <div className="section-head">
                    <div>
                        <p>权限矩阵</p>
                        <h2>角色能力</h2>
                    </div>
                </div>
                <div className="role-grid">
                    {roles.map((role) => <RolePermissionEditor key={role.id} role={role} groupedPermissions={permissions} />)}
                </div>
            </section>
        </Layout>
    );
}

function UserRoleEditor({ user, roles }) {
    const initialRoleIds = user.roles.map((role) => role.id);
    const [selected, setSelected] = useState(initialRoleIds);
    const [saved, setSaved] = useState(initialRoleIds);
    const [processing, setProcessing] = useState(false);
    const isDirty = !sameIds(selected, saved);

    function toggle(id) {
        setSelected((next) => next.includes(id) ? next.filter((item) => item !== id) : [...next, id]);
    }

    function save() {
        setProcessing(true);
        router.put(`/admin/users/${user.id}/roles`, { roles: selected }, {
            preserveScroll: true,
            onSuccess: () => setSaved([...selected]),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <article className="user-row">
            <div>
                <strong>{user.name}</strong>
                <span>{user.email}</span>
                <small>{selected.length ? `已选择 ${selected.length} 个角色` : '尚未分配业务角色'}</small>
            </div>
            <div className="check-row">
                {roles.map((role) => (
                    <label key={role.id}>
                        <input type="checkbox" checked={selected.includes(role.id)} onChange={() => toggle(role.id)} />
                        {role.label}
                    </label>
                ))}
            </div>
            <div className="user-row-actions">
                {isDirty && <span className="unsaved-badge" role="status">有未保存修改</span>}
                <button
                    type="button"
                    onClick={save}
                    disabled={!isDirty || processing}
                    aria-label={`保存${user.name}的角色`}
                >
                    {processing ? '保存中...' : '保存角色'}
                </button>
            </div>
        </article>
    );
}

function RolePermissionEditor({ role, groupedPermissions }) {
    const initialPermissionIds = role.permissions.map((permission) => permission.id);
    const [selected, setSelected] = useState(initialPermissionIds);
    const [saved, setSaved] = useState(initialPermissionIds);
    const [processing, setProcessing] = useState(false);
    const isDirty = !sameIds(selected, saved);
    const modules = Object.entries(groupedPermissions);

    function toggle(id) {
        setSelected((next) => next.includes(id) ? next.filter((item) => item !== id) : [...next, id]);
    }

    function save() {
        setProcessing(true);
        router.put(`/admin/roles/${role.id}/permissions`, { permissions: selected }, {
            preserveScroll: true,
            onSuccess: () => setSaved([...selected]),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <details className="role-card">
            <summary className="role-card-head">
                <div>
                    <strong>{role.label}</strong>
                    <span>{businessText(role.description)}</span>
                </div>
                <span className="pill">{role.locked ? '内置' : `${selected.length} 项权限`}</span>
            </summary>
            <div className="permission-matrix">
                {modules.map(([module, perms]) => (
                    <section key={module}>
                        <h4>{permissionGroupLabel(perms, module)}</h4>
                        {perms.map((permission) => (
                            <label key={permission.id}>
                                <input disabled={role.locked} type="checkbox" checked={selected.includes(permission.id)} onChange={() => toggle(permission.id)} />
                                {businessText(permission.label)}
                            </label>
                        ))}
                    </section>
                ))}
            </div>
            {!role.locked && (
                <div className="role-card-actions">
                    {isDirty && <span className="unsaved-badge" role="status">权限有修改，尚未保存</span>}
                    <button type="button" onClick={save} disabled={!isDirty || processing}>
                        {processing ? '保存中...' : `保存“${role.label}”权限`}
                    </button>
                </div>
            )}
        </details>
    );
}

function sameIds(left, right) {
    return [...left].sort().join('|') === [...right].sort().join('|');
}

function RbacAside() {
    return (
        <>
            <div className="side-title">
                <ShieldCheck size={18} />
                <div>
                    <p>权限规则</p>
                    <h3>默认边界</h3>
                </div>
            </div>
            <div className="permission-list">
                <span className="allowed">基础角色：大盘</span>
                <span className="allowed">基础角色：提起申请</span>
                <span className="blocked">基础角色：不可查看或维护业务资料</span>
                <span className="blocked">基础角色：RBAC</span>
            </div>
            <p className="muted">给用户增加业务角色后，左侧导航以及业务资料的查看、新建、编辑和删除权限会立即生效。</p>
        </>
    );
}

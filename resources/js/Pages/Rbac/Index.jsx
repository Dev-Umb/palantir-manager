import { Head, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import Layout from '../../Components/Layout';

export default function Index({ users, roles, permissions }) {
    return (
        <Layout title="用户与权限" eyebrow="RBAC Console" aside={<RbacAside />}>
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
    const [selected, setSelected] = useState(user.roles.map((role) => role.id));

    function toggle(id) {
        setSelected((next) => next.includes(id) ? next.filter((item) => item !== id) : [...next, id]);
    }

    function save() {
        router.put(`/admin/users/${user.id}/roles`, { roles: selected }, { preserveScroll: true });
    }

    return (
        <article className="user-row">
            <div>
                <strong>{user.name}</strong>
                <span>{user.email}</span>
            </div>
            <div className="check-row">
                {roles.map((role) => (
                    <label key={role.id}>
                        <input type="checkbox" checked={selected.includes(role.id)} onChange={() => toggle(role.id)} />
                        {role.label}
                    </label>
                ))}
            </div>
            <button type="button" onClick={save}>保存</button>
        </article>
    );
}

function RolePermissionEditor({ role, groupedPermissions }) {
    const [selected, setSelected] = useState(role.permissions.map((permission) => permission.id));
    const modules = Object.entries(groupedPermissions);

    function toggle(id) {
        setSelected((next) => next.includes(id) ? next.filter((item) => item !== id) : [...next, id]);
    }

    function save() {
        router.put(`/admin/roles/${role.id}/permissions`, { permissions: selected }, { preserveScroll: true });
    }

    return (
        <details className="role-card">
            <summary className="role-card-head">
                <div>
                    <strong>{role.label}</strong>
                    <span>{role.description}</span>
                </div>
                <span className="pill">{role.locked ? '内置' : `${selected.length} 项权限`}</span>
            </summary>
            <div className="permission-matrix">
                {modules.map(([module, perms]) => (
                    <section key={module}>
                        <h4>{module}</h4>
                        {perms.map((permission) => (
                            <label key={permission.id}>
                                <input disabled={role.locked} type="checkbox" checked={selected.includes(permission.id)} onChange={() => toggle(permission.id)} />
                                {permission.label}
                            </label>
                        ))}
                    </section>
                ))}
            </div>
            {!role.locked && <button type="button" onClick={save}>保存角色权限</button>}
        </details>
    );
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
                <span className="blocked">基础角色：本体 CRUD</span>
                <span className="blocked">基础角色：RBAC</span>
            </div>
            <p className="muted">给用户增加业务角色后，左侧导航和对象 CRUD 权限会立即按角色权限矩阵生效。</p>
        </>
    );
}

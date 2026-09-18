export default function PasswordFields({ form, prefix, reset = false }) {
    const fields = [
        ['current_password', reset ? '您的当前密码' : '当前密码', 'current-password'],
        ['password', reset ? '临时密码' : '新密码', 'new-password'],
        ['password_confirmation', reset ? '确认临时密码' : '确认新密码', 'new-password'],
    ];

    return fields.map(([name, label, autoComplete]) => (
        <label key={name} htmlFor={`${prefix}-${name}`}>
            <span id={`${prefix}-${name}-label`}>{label}</span>
            <input
                id={`${prefix}-${name}`}
                name={name}
                aria-labelledby={`${prefix}-${name}-label`}
                type="password"
                autoComplete={autoComplete}
                value={form.data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                required
                minLength={name === 'current_password' ? undefined : 8}
                aria-invalid={Boolean(form.errors[name])}
                aria-describedby={form.errors[name] ? `${prefix}-${name}-error` : undefined}
            />
            {form.errors[name] && <p id={`${prefix}-${name}-error`} className="form-error" role="alert">{form.errors[name]}</p>}
        </label>
    ));
}

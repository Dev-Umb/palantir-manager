import 'package:flutter/material.dart';

import 'api.dart';
import 'ui.dart';
import 'projects.dart';
import 'updates.dart';

export 'procurement.dart';

class AccountPage extends StatelessWidget {
  final PalantirApi api;
  const AccountPage({super.key, required this.api});
  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(20),
    children: [
      const SizedBox(height: 14),
      const Text(
        '我的',
        style: TextStyle(fontSize: 25, fontWeight: FontWeight.w600),
      ),
      const SizedBox(height: 22),
      panel(
        Row(
          children: [
            const CircleAvatar(
              radius: 28,
              child: Icon(Icons.person_outline, size: 30),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    textOf(api.user?['name']),
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(textOf(api.user?['email'])),
                  Text(
                    textOf(api.session['roles']),
                    style: const TextStyle(color: Colors.blueGrey),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      Card(
        child: ListTile(
          leading: const Icon(Icons.lock_outline),
          title: const Text('账号与安全'),
          subtitle: const Text('修改登录邮箱和密码'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => push(context, SecurityPage(api: api)),
        ),
      ),
      Card(
        child: ListTile(
          leading: const Icon(Icons.notifications_outlined),
          title: const Text('通知中心'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => push(context, NotificationsPage(api: api)),
        ),
      ),
      const UpdateTile(),
      note('鑫源昌智造中枢 · Android 业务端'),
      OutlinedButton(
        onPressed: () async {
          try {
            await api.logout();
          } catch (e) {
            if (context.mounted) toast(context, '$e');
          }
        },
        child: const Text('退出登录'),
      ),
    ],
  );
}

class SecurityPage extends StatefulWidget {
  final PalantirApi api;
  const SecurityPage({super.key, required this.api});
  @override
  State<SecurityPage> createState() => _SecurityPageState();
}

class _SecurityPageState extends State<SecurityPage> {
  final email = TextEditingController(),
      current = TextEditingController(),
      password = TextEditingController(),
      confirm = TextEditingController();
  bool busy = false;
  String? error;
  @override
  void initState() {
    super.initState();
    email.text = widget.api.user?['email'] ?? '';
  }

  @override
  void dispose() {
    for (final c in [email, current, password, confirm]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> save(bool changeEmail) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.send(
        changeEmail ? '/settings/email' : '/settings/password',
        changeEmail
            ? {'email': email.text.trim(), 'current_password': current.text}
            : {
                'current_password': current.text,
                'password': password.text,
                'password_confirmation': confirm.text,
              },
        method: 'PUT',
      );
      await widget.api.bootstrap();
      if (mounted) {
        current.clear();
        password.clear();
        confirm.clear();
        toast(context, changeEmail ? '登录邮箱已更新' : '密码已修改');
      }
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('账号与安全')),
    body: ListView(
      padding: const EdgeInsets.all(20),
      children: [
        if (error != null) errorBox(error!),
        TextField(
          controller: current,
          obscureText: true,
          decoration: const InputDecoration(labelText: '当前密码'),
        ),
        const SizedBox(height: 24),
        TextField(
          controller: email,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(labelText: '登录邮箱'),
        ),
        const SizedBox(height: 12),
        OutlinedButton(
          onPressed: busy ? null : () => save(true),
          child: const Text('更新邮箱'),
        ),
        const SizedBox(height: 24),
        TextField(
          controller: password,
          obscureText: true,
          decoration: const InputDecoration(labelText: '新密码（至少 8 位）'),
        ),
        const SizedBox(height: 16),
        TextField(
          controller: confirm,
          obscureText: true,
          decoration: const InputDecoration(labelText: '确认新密码'),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: busy ? null : () => save(false),
          child: Text(busy ? '正在保存…' : '修改密码'),
        ),
      ],
    ),
  );
}

class NotificationsPage extends StatefulWidget {
  final PalantirApi api;
  const NotificationsPage({super.key, required this.api});
  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  int revision = 0, page = 1;
  Future<void> read(Map<String, dynamic> n) async {
    try {
      await widget.api.send(
        '/notifications/${n['id']}/read',
        {},
        method: 'PATCH',
      );
      if (mounted) setState(() => revision++);
    } catch (e) {
      if (mounted) toast(context, '$e');
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('通知中心')),
    body: AsyncPage(
      key: ValueKey('$revision-$page'),
      load: () => widget.api.page('/notifications?page=$page'),
      builder: (data) {
        final pagination = mapOf(data['notifications']);
        final items = maps(pagination['data']);
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              '${data['unreadCount'] ?? 0} 条未读提醒',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 18),
            if (items.isEmpty) note('暂无项目提醒。'),
            ...items.map(
              (n) => panel(
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    tag(textOf(n['type_label'])),
                    const SizedBox(height: 10),
                    Text(
                      textOf(n['message']),
                      style: const TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    kv('关联项目', mapOf(n['project'])['name']),
                    Row(
                      children: [
                        if (n['can_view_project'] == true)
                          TextButton(
                            onPressed: () => openFlow(
                              context,
                              ProjectDetail(
                                api: widget.api,
                                id: mapOf(n['project'])['id'].toString(),
                              ),
                            ),
                            child: const Text('查看项目'),
                          ),
                        const Spacer(),
                        TextButton(
                          onPressed: () => read(n),
                          child: const Text('标记已读'),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                TextButton(
                  onPressed: page > 1 ? () => setState(() => page--) : null,
                  child: const Text('上一页'),
                ),
                Text('$page / ${pagination['last_page'] ?? 1}'),
                TextButton(
                  onPressed: page < (pagination['last_page'] ?? 1)
                      ? () => setState(() => page++)
                      : null,
                  child: const Text('下一页'),
                ),
              ],
            ),
          ],
        );
      },
    ),
  );
}

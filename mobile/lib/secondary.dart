import 'package:flutter/material.dart';

import 'api.dart';
import 'ui.dart';
import 'projects.dart';
import 'attachments.dart';
import 'updates.dart';

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

class ProcurementPage extends StatefulWidget {
  final PalantirApi api;
  const ProcurementPage({super.key, required this.api});
  @override
  State<ProcurementPage> createState() => _ProcurementPageState();
}

class _ProcurementPageState extends State<ProcurementPage> {
  String search = '';
  bool history = false;
  int page = 1;
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('招采参考')),
    body: Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: TextField(
            decoration: const InputDecoration(
              hintText: '搜索项目、采购单位、产品',
              prefixIcon: Icon(Icons.search),
            ),
            onSubmitted: (s) => setState(() {
              search = s;
              page = 1;
            }),
          ),
        ),
        SwitchListTile(
          title: const Text('包含已截止公告'),
          value: history,
          onChanged: (v) => setState(() {
            history = v;
            page = 1;
          }),
        ),
        Expanded(
          child: AsyncPage(
            key: ValueKey('$search-$history-$page'),
            load: () => widget.api.page(
              '/procurement-hub?q=${Uri.encodeQueryComponent(search)}&history=${history ? 1 : 0}&page=$page',
            ),
            builder: (data) {
              final pagination = mapOf(data['notices']);
              final items = maps(pagination['data']);
              return ListView(
                padding: const EdgeInsets.all(20),
                children: [
                  if (items.isEmpty) note('暂无匹配公告，请调整搜索条件。'),
                  ...items.map(
                    (n) => Card(
                      child: InkWell(
                        onTap: () => push(
                          context,
                          NoticeDetail(api: widget.api, id: n['id'].toString()),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              tag(
                                const {
                                      'notice': '采购公告',
                                      'award': '成交公告',
                                      'result': '结果公告',
                                    }[n['kind']] ??
                                    textOf(n['kind']),
                              ),
                              const SizedBox(height: 12),
                              Text(
                                textOf(n['title']),
                                style: const TextStyle(
                                  fontSize: 19,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              kv('采购单位', n['buyer']),
                              kv('投标截止', n['deadline']),
                              Text(
                                '${textOf(n['region'])} · ${textOf(n['product'])}',
                                style: const TextStyle(color: Colors.blueGrey),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      TextButton(
                        onPressed: page > 1
                            ? () => setState(() => page--)
                            : null,
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
        ),
      ],
    ),
  );
}

class NoticeDetail extends StatelessWidget {
  final PalantirApi api;
  final String id;
  const NoticeDetail({super.key, required this.api, required this.id});
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('公告详情')),
    body: AsyncPage(
      load: () => api.page('/procurement-hub/notices/$id'),
      builder: (data) {
        final n = mapOf(data['notice']), facts = mapOf(n['facts']);
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              textOf(n['title']),
              style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 20),
            panel(
              Column(
                children: [
                  kv('采购单位', n['buyer']),
                  kv('地区', n['region']),
                  kv('产品', n['product']),
                  kv('发布时间', n['published_at']),
                  kv('投标截止', n['deadline']),
                  kv('报名开始', facts['registration_start']),
                  kv('报名截止', facts['registration_end']),
                  kv('币种', facts['currency']),
                  if (facts['amount_type'] == 'candidate')
                    kv('候选人报价（非成交）', facts['amount']),
                  if (facts['amount_type'] == 'award')
                    kv('成交金额', facts['amount']),
                  kv(
                    '预算（原币种）',
                    facts['budget'] ??
                        facts['budget_amount'] ??
                        (facts['amount_type'] == 'budget'
                            ? facts['amount']
                            : null),
                  ),
                ],
              ),
            ),
            note('截止时间与资格条件请以原公告为准。'),
            if ((n['missing'] as List? ?? []).isNotEmpty)
              note('待核实：${textOf(n['missing'])}'),
            if (mapOf(data['screening']).isNotEmpty)
              panel(
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      textOf(mapOf(data['screening'])['label']),
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    ...((mapOf(data['screening'])['reasons'] as List?) ?? [])
                        .map((r) => note(textOf(r))),
                    note(textOf(mapOf(data['screening'])['limitation'])),
                  ],
                ),
              ),
            const Text(
              '已有分析与证据',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
            ),
            if (maps(data['reports']).isEmpty) note('暂无已发布分析，当前仅供公告参考。'),
            ...maps(data['reports'])
                .map((r) => PublishedReport(api: api, report: r)),
            const Text(
              '公开资料',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
            ),
            if (maps(n['evidence']).isEmpty) note('尚无可查看的原文资料。'),
            ...maps(n['evidence']).map(
              (e) => panel(
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SelectableText(
                      textOf(e['text']),
                      style: const TextStyle(height: 1.6),
                    ),
                    TextButton.icon(
                      onPressed: () =>
                          openExternal(context, Uri.parse(e['url'])),
                      icon: const Icon(Icons.open_in_new),
                      label: const Text('查看原公告'),
                    ),
                  ],
                ),
              ),
            ),
          ],
        );
      },
    ),
  );
}

class PublishedReport extends StatelessWidget {
  final PalantirApi api;
  final Map<String, dynamic> report;
  const PublishedReport({super.key, required this.api, required this.report});
  @override
  Widget build(BuildContext context) {
    final result = mapOf(report['result']);
    return panel(
      ExpansionTile(
        title: Text(textOf(report['query'] ?? '公告分析')),
        subtitle: Text(
          report['status'] == 'stale'
              ? '项目参考已变化，旧结论不再展示'
              : report['stale_at'] != null
              ? '历史分析 · 依据已更新'
              : '已发布分析',
        ),
        tilePadding: EdgeInsets.zero,
        children: [
          if (result.isEmpty) note('当前没有可展示的有效结论。'),
          for (final section in ['recommendation', 'analysis']) ...[
            ...maps(mapOf(result[section])['claims'])
                .where(
                  (c) =>
                      section != 'analysis' ||
                      c['section'] == null ||
                      ['price', 'market'].contains(c['section']),
                )
                .map(
                  (claim) => Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      SelectableText(
                        textOf(claim['text']),
                        style: const TextStyle(height: 1.6),
                      ),
                      for (final id in claim['evidence_ids'] as List? ?? [])
                        TextButton(
                          onPressed: () {
                            final evidence = maps(report['evidence'])
                                .where((e) => e['id'] == id)
                                .firstOrNull;
                            if (evidence != null) {
                              replacePage(
                                context,
                                NoticeDetail(
                                  api: api,
                                  id: evidence['notice_id'].toString(),
                                ),
                              );
                            }
                          },
                          child: Text('查看证据 $id'),
                        ),
                    ],
                  ),
                ),
            for (final limitation
                in mapOf(result[section])['limitations'] as List? ?? [])
              note(textOf(limitation)),
          ],
          for (final group in maps(mapOf(result['statistics'])['groups']))
            panel(
              Column(
                children: [
                  kv('产品', mapOf(group['basis'])['product']),
                  kv('地区', mapOf(group['basis'])['region']),
                  kv('规格', mapOf(group['basis'])['spec']),
                  kv('税费', mapOf(group['basis'])['tax']),
                  kv('运费', mapOf(group['basis'])['freight']),
                  kv(
                    '交易方式',
                    mapOf(group['basis'])['transaction'] == 'rental'
                        ? '租赁'
                        : '购买',
                  ),
                  kv(
                    '金额口径',
                    mapOf(group['basis'])['amount_type'] == 'candidate'
                        ? '候选人报价，非成交'
                        : '成交金额',
                  ),
                  kv(
                    '中位数',
                    '${textOf(group['median'])} / ${textOf(mapOf(group['basis'])['unit'])}',
                  ),
                  kv('样本数', group['count']),
                  kv('区间', '${textOf(group['min'])}–${textOf(group['max'])}'),
                ],
              ),
            ),
          for (final limitation in result['limitations'] as List? ?? [])
            note(textOf(limitation)),
          for (final issue in report['audit_issues'] as List? ?? [])
            note(textOf(issue)),
        ],
      ),
    );
  }
}

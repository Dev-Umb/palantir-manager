import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'session_store.dart';

import 'api.dart';
import 'environment.dart';
import 'ui.dart';
import 'projects.dart';
import 'assistant.dart';
import 'secondary.dart';
import 'updates.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const PalantirApp());
}

class PalantirApp extends StatefulWidget {
  final PalantirApi? api;
  const PalantirApp({super.key, this.api});
  @override
  State<PalantirApp> createState() => _PalantirAppState();
}

class _PalantirAppState extends State<PalantirApp> {
  late final PalantirApi api;
  bool restoring = true;
  bool updateChecked = false;
  String? restoreError;
  final navigatorKey = GlobalKey<NavigatorState>();
  @override
  void initState() {
    super.initState();
    api =
        widget.api ??
        PalantirApi(resolveApiUrl(), sessionStore: AndroidSessionStore());
    api.addListener(onSessionChanged);
    restore();
  }

  Future<void> restore() async {
    setState(() {
      restoring = true;
      restoreError = null;
    });
    try {
      await api.restoreSession();
      if (mounted) {
        setState(() => restoring = false);
        if (!updateChecked && widget.api == null) {
          updateChecked = true;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            final context = navigatorKey.currentContext;
            if (context != null) checkForUpdate(context, automatic: true);
          });
        }
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          restoring = false;
          restoreError = '暂时无法恢复登录，请检查网络后重试。';
        });
      }
    }
  }

  Future<void> returnToLogin() async {
    setState(() => restoring = true);
    try {
      await api.clearSession();
      if (mounted) {
        setState(() {
          restoring = false;
          restoreError = null;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          restoring = false;
          restoreError = '无法清除设备上的登录信息，请重试。';
        });
      }
    }
  }

  void onSessionChanged() {
    if (api.user == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        navigatorKey.currentState?.popUntil((route) => route.isFirst);
      });
    }
  }

  @override
  void dispose() {
    api.removeListener(onSessionChanged);
    if (widget.api == null) api.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    navigatorKey: navigatorKey,
    title: '鑫源昌智造中枢',
    debugShowCheckedModeBanner: false,
    theme: appTheme(),
    home: ListenableBuilder(
      listenable: api,
      builder: (context, _) {
        if (restoring || restoreError != null || api.sessionClearFailed) {
          return Scaffold(
            body: SafeArea(
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.hub_outlined, size: 48, color: steel),
                      const SizedBox(height: 24),
                      if (restoring) ...[
                        const CircularProgressIndicator(),
                        const SizedBox(height: 20),
                        const Text('正在恢复登录…'),
                      ] else ...[
                        Text(
                          api.sessionClearFailed
                              ? '无法清除设备上的登录信息，请重试。'
                              : restoreError!,
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: api.sessionClearFailed
                              ? returnToLogin
                              : restore,
                          child: const Text('重试'),
                        ),
                        TextButton(
                          onPressed: returnToLogin,
                          child: const Text('重新登录'),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          );
        }
        return api.user == null || api.authenticating
            ? LoginPage(api: api)
            : BusinessShell(api: api);
      },
    ),
    builder: (context, child) => child!,
  );
}

class LoginPage extends StatefulWidget {
  final PalantirApi api;
  const LoginPage({super.key, required this.api});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final email = TextEditingController(), password = TextEditingController();
  bool busy = false, obscure = true, remember = true;
  String? error;
  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> login() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.login(email.text, password.text, remember: remember);
      TextInput.finishAutofillContext(shouldSave: remember);
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: AutofillGroup(
        onDisposeAction: AutofillContextAction.cancel,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            const SizedBox(height: 56),
            const Icon(Icons.hub_outlined, size: 48, color: steel),
            const SizedBox(height: 24),
            const Text(
              '鑫源昌智造中枢',
              style: TextStyle(fontSize: 27, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 10),
            const Text(
              '业务在手，进展心中有数。',
              style: TextStyle(color: Colors.blueGrey),
            ),
            const SizedBox(height: 36),
            if (error != null) errorBox(error!),
            TextField(
              controller: email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.username],
              decoration: const InputDecoration(labelText: '登录邮箱'),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: password,
              obscureText: obscure,
              autofillHints: const [AutofillHints.password],
              onSubmitted: (_) {
                if (!busy) login();
              },
              decoration: InputDecoration(
                labelText: '密码',
                suffixIcon: IconButton(
                  onPressed: () => setState(() => obscure = !obscure),
                  icon: Icon(
                    obscure
                        ? Icons.visibility_outlined
                        : Icons.visibility_off_outlined,
                  ),
                ),
              ),
            ),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              title: const Text('保持登录'),
              subtitle: const Text('下次打开自动登录，退出登录后清除'),
              value: remember,
              onChanged: busy
                  ? null
                  : (value) => setState(() => remember = value ?? false),
            ),
            const SizedBox(height: 12),
            FilledButton(
              onPressed: busy ? null : login,
              child: Text(busy ? '正在登录…' : '登录'),
            ),
            note('使用平台账号登录。当前连接：${widget.api.base.host}\n忘记密码请联系管理员。'),
          ],
        ),
      ),
    ),
  );
}

class BusinessShell extends StatefulWidget {
  final PalantirApi api;
  const BusinessShell({super.key, required this.api});
  @override
  State<BusinessShell> createState() => _BusinessShellState();
}

class _BusinessShellState extends State<BusinessShell> {
  int index = 0;
  final visited = <int>{0};
  void select(int next) => setState(() {
    index = next;
    visited.add(next);
  });
  @override
  Widget build(BuildContext context) {
    final api = widget.api;
    return Scaffold(
      body: SafeArea(
        child: IndexedStack(
          index: index,
          children: [
            HomePage(api: api, onProjects: () => select(1)),
            if (visited.contains(1))
              ProjectsPage(api: api)
            else
              const SizedBox.shrink(),
            if (visited.contains(2))
              CustomersPage(api: api)
            else
              const SizedBox.shrink(),
            if (visited.contains(3))
              AccountPage(api: api)
            else
              const SizedBox.shrink(),
          ],
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: select,
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: '工作台',
          ),
          NavigationDestination(
            icon: Icon(Icons.folder_outlined),
            selectedIcon: Icon(Icons.folder),
            label: '项目',
          ),
          NavigationDestination(
            icon: Icon(Icons.people_outline),
            selectedIcon: Icon(Icons.people),
            label: '客户',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            selectedIcon: Icon(Icons.person),
            label: '我的',
          ),
        ],
      ),
    );
  }
}

class HomePage extends StatefulWidget {
  final PalantirApi api;
  final VoidCallback onProjects;
  const HomePage({super.key, required this.api, required this.onProjects});
  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  int revision = 0;
  Future<void> openProject(Map<String, dynamic> r) async {
    await push(context, ProjectDetail(api: widget.api, id: r['id'].toString()));
    if (mounted) setState(() => revision++);
  }

  @override
  Widget build(BuildContext context) {
    final api = widget.api;
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 18, 12, 10),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      '鑫源昌智造中枢 / 业务端',
                      style: TextStyle(fontSize: 12, color: Colors.blueGrey),
                    ),
                    const SizedBox(height: 5),
                    Text(
                      '你好，${api.user?['name'] ?? ''}',
                      style: const TextStyle(
                        fontSize: 25,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                tooltip: '通知中心',
                onPressed: () => push(context, NotificationsPage(api: api)),
                icon: const Icon(Icons.notifications_outlined),
              ),
            ],
          ),
        ),
        Expanded(
          child: AsyncPage(
            key: ValueKey(revision),
            load: () => api.page('/'),
            builder: (data) {
              final panelData = mapOf(
                mapOf(mapOf(data['cockpit'])['panels'])['project_amounts'],
              );
              final values = maps(panelData['company']);
              Map<String, dynamic> money(String key) =>
                  values.firstWhere((m) => m['key'] == key, orElse: () => {});
              final unpaid = money('unpaid_amount');
              final projects = maps(data['recentProjects']);
              final risks = maps(data['notificationRisks']);
              return ListView(
                padding: const EdgeInsets.all(20),
                children: [
                  if (api.user?['is_password_changed'] == false)
                    note('请在“我的 → 账号与安全”修改初始密码。'),
                  Container(
                    padding: const EdgeInsets.all(22),
                    decoration: BoxDecoration(
                      color: ink,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          '我的可见项目 · 未回款金额',
                          style: TextStyle(color: Colors.white70),
                        ),
                        const SizedBox(height: 10),
                        Text(
                          amount(unpaid['value'], wan: true),
                          style: const TextStyle(
                            fontSize: 36,
                            color: Colors.white,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                        const Divider(color: Colors.white24, height: 30),
                        Row(
                          children: [
                            Expanded(
                              child: _hero(
                                '已发生金额',
                                money('occurred_amount')['value'],
                              ),
                            ),
                            Expanded(
                              child: _hero(
                                '已回款金额',
                                money('paid_amount')['value'],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        Text(
                          '未回款覆盖 ${mapOf(unpaid['coverage'])['valid'] ?? 0}/${mapOf(unpaid['coverage'])['total'] ?? 0} · 空值不计为零',
                          style: const TextStyle(
                            color: Colors.white70,
                            fontSize: 12,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 22),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      if (api.can('object.project.create'))
                        _shortcut(Icons.add, '新建项目', () async {
                          await push(context, ProjectEditor(api: api));
                          if (mounted) setState(() => revision++);
                        }),
                      _shortcut(Icons.search, '查项目', widget.onProjects),
                      if (api.canUpload)
                        _shortcut(
                          Icons.upload_file_outlined,
                          '上传合同',
                          () => push(context, ContractIntakePage(api: api)),
                        ),
                      if (api.can('ai.harness.view'))
                        _shortcut(
                          Icons.auto_awesome_outlined,
                          'AI 助手',
                          () => push(context, AssistantPage(api: api)),
                        ),
                    ],
                  ),
                  const SizedBox(height: 22),
                  _heading(
                    '需要关注',
                    () => push(context, NotificationsPage(api: api)),
                  ),
                  if (risks.isEmpty) note('暂无需要处理的合同或回款提醒。'),
                  ...risks
                      .take(3)
                      .map(
                        (r) => Card(
                          child: ListTile(
                            leading: const Icon(
                              Icons.assignment_late_outlined,
                              color: steel,
                            ),
                            title: Text(
                              textOf(
                                r['message'] ?? r['title'] ?? r['type_label'],
                              ),
                            ),
                            subtitle: Text(
                              textOf(
                                r['project_name'] ??
                                    mapOf(r['project'])['name'],
                              ),
                            ),
                            onTap: () =>
                                push(context, NotificationsPage(api: api)),
                          ),
                        ),
                      ),
                  if (api.canProcurement)
                    Card(
                      child: ListTile(
                        leading: const Icon(
                          Icons.newspaper_outlined,
                          color: steel,
                        ),
                        title: const Text('招采参考'),
                        subtitle: const Text('查看机会、截止时间与公开资料'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => push(context, ProcurementPage(api: api)),
                      ),
                    ),
                  _heading('最近项目', widget.onProjects),
                  if (projects.isEmpty) note('还没有可查看的项目。'),
                  ...projects.map(
                    (r) => ProjectCard(record: r, onTap: () => openProject(r)),
                  ),
                ],
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _hero(String label, dynamic value) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
      const SizedBox(height: 5),
      Text(
        amount(value, wan: true),
        style: const TextStyle(color: Colors.white, fontSize: 19),
      ),
    ],
  );
  Widget _shortcut(IconData icon, String label, VoidCallback tap) => InkWell(
    onTap: tap,
    borderRadius: BorderRadius.circular(12),
    child: Padding(
      padding: const EdgeInsets.all(4),
      child: Column(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Icon(icon, color: steel),
          ),
          const SizedBox(height: 8),
          Text(label, style: const TextStyle(fontSize: 12)),
        ],
      ),
    ),
  );
  Widget _heading(String title, VoidCallback tap) => Row(
    mainAxisAlignment: MainAxisAlignment.spaceBetween,
    children: [
      Text(
        title,
        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 18),
      ),
      TextButton(onPressed: tap, child: const Text('查看全部')),
    ],
  );
}

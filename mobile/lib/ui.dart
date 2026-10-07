import 'package:flutter/material.dart';

import 'api.dart';

const steel = Color(0xff246b95),
    ink = Color(0xff193951),
    canvas = Color(0xfff2f4f7);
ThemeData appTheme() => ThemeData(
  useMaterial3: true,
  colorScheme: ColorScheme.fromSeed(seedColor: steel),
  scaffoldBackgroundColor: canvas,
  appBarTheme: const AppBarTheme(backgroundColor: canvas, centerTitle: false),
  inputDecorationTheme: InputDecorationTheme(
    filled: true,
    fillColor: Colors.white,
    border: OutlineInputBorder(
      borderRadius: BorderRadius.circular(12),
      borderSide: const BorderSide(color: Color(0xffdde4ea)),
    ),
    contentPadding: const EdgeInsets.all(14),
  ),
  cardTheme: CardThemeData(
    color: Colors.white,
    elevation: 0,
    margin: const EdgeInsets.only(bottom: 12),
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
      side: const BorderSide(color: Color(0xffdde4ea)),
    ),
  ),
  filledButtonTheme: FilledButtonThemeData(
    style: FilledButton.styleFrom(
      minimumSize: const Size(0, 50),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(13)),
    ),
  ),
);
Widget panel(Widget child, {EdgeInsets padding = const EdgeInsets.all(16)}) =>
    Card(
      child: Padding(padding: padding, child: child),
    );
Widget kv(String title, dynamic value) => Padding(
  padding: const EdgeInsets.symmetric(vertical: 9),
  child: Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Expanded(
        child: Text(
          title,
          style: const TextStyle(color: Colors.blueGrey, fontSize: 13),
        ),
      ),
      const SizedBox(width: 12),
      Flexible(child: Text(textOf(value), textAlign: TextAlign.right)),
    ],
  ),
);
Widget tag(String value) => Container(
  padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
  decoration: BoxDecoration(
    color: steel.withValues(alpha: .09),
    borderRadius: BorderRadius.circular(6),
  ),
  child: Text(value, style: const TextStyle(fontSize: 12, color: steel)),
);
Widget note(String value) => Container(
  width: double.infinity,
  margin: const EdgeInsets.symmetric(vertical: 10),
  padding: const EdgeInsets.all(14),
  decoration: BoxDecoration(
    color: steel.withValues(alpha: .07),
    borderRadius: BorderRadius.circular(12),
  ),
  child: Text(
    value,
    style: const TextStyle(color: Colors.blueGrey, fontSize: 13, height: 1.6),
  ),
);
Widget errorBox(String value) => Container(
  width: double.infinity,
  padding: const EdgeInsets.all(14),
  margin: const EdgeInsets.only(bottom: 12),
  decoration: BoxDecoration(
    color: const Color(0xffffe9e5),
    borderRadius: BorderRadius.circular(12),
  ),
  child: Text(value, style: const TextStyle(color: Color(0xff96352e))),
);
void toast(BuildContext context, String message) =>
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
Future<T?> push<T>(BuildContext context, Widget page) =>
    Navigator.of(context).push<T>(MaterialPageRoute(builder: (_) => page));

Future<T?> openFlow<T>(BuildContext context, Widget page) =>
    Navigator.of(context).pushAndRemoveUntil<T>(
      MaterialPageRoute(builder: (_) => page),
      (route) => route.isFirst,
    );

Future<T?> replacePage<T>(BuildContext context, Widget page) =>
    Navigator.of(context)
        .pushReplacement<T, void>(MaterialPageRoute(builder: (_) => page));

class AsyncPage extends StatefulWidget {
  final Future<Map<String, dynamic>> Function() load;
  final Widget Function(Map<String, dynamic>) builder;
  const AsyncPage({super.key, required this.load, required this.builder});
  @override
  State<AsyncPage> createState() => _AsyncPageState();
}

class _AsyncPageState extends State<AsyncPage> {
  late Future<Map<String, dynamic>> future;
  @override
  void initState() {
    super.initState();
    future = widget.load();
  }

  @override
  void didUpdateWidget(covariant AsyncPage old) {
    super.didUpdateWidget(old);
    if (old.key != widget.key) future = widget.load();
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
    future: future,
    builder: (context, s) {
      if (s.hasError) {
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            errorBox('${s.error}'),
            FilledButton(
              onPressed: () => setState(() => future = widget.load()),
              child: const Text('重试'),
            ),
          ],
        );
      }
      if (!s.hasData) return const Center(child: CircularProgressIndicator());
      return RefreshIndicator(
        onRefresh: () async {
          setState(() => future = widget.load());
          await future;
        },
        child: widget.builder(s.data!),
      );
    },
  );
}

class ProjectCard extends StatelessWidget {
  final Map<String, dynamic> record;
  final VoidCallback onTap;
  const ProjectCard({super.key, required this.record, required this.onTap});
  @override
  Widget build(BuildContext context) {
    final p = mapOf(record['payload']);
    final labels = mapOf(record['display']);
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      textOf(record['code']),
                      style: const TextStyle(
                        color: Colors.blueGrey,
                        fontSize: 11,
                      ),
                    ),
                  ),
                  tag(textOf(p['overall_status'])),
                ],
              ),
              const SizedBox(height: 10),
              Text(
                textOf(p['name'] ?? record['title']),
                style: const TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 5),
              Text(
                textOf(labels['customer_id'] ?? p['customer_name']),
                style: const TextStyle(color: Colors.blueGrey, fontSize: 13),
              ),
              const Divider(height: 28),
              Row(
                children: [
                  Expanded(
                    child: _metric(
                      '合同金额',
                      amount(p['contract_amount'], wan: true),
                    ),
                  ),
                  Expanded(
                    child: _metric(
                      '未回款金额',
                      amount(p['unpaid_amount'], wan: true),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _metric(String l, String v) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(l, style: const TextStyle(fontSize: 12, color: Colors.blueGrey)),
      const SizedBox(height: 4),
      Text(
        v,
        style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w600),
      ),
    ],
  );
}

class EditorLoading extends StatefulWidget {
  const EditorLoading({super.key});
  @override
  State<EditorLoading> createState() => _EditorLoadingState();
}

class _EditorLoadingState extends State<EditorLoading>
    with SingleTickerProviderStateMixin {
  late final AnimationController animation = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  );
  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) {
      animation.stop();
      animation.value = .6;
    } else {
      animation.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    animation.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Semantics(
    label: '正在加载项目资料',
    child: ListView(
      padding: const EdgeInsets.all(20),
      children: [
        const Row(
          children: [
            Icon(Icons.edit_note, color: steel),
            SizedBox(width: 10),
            Text('正在加载项目资料…'),
          ],
        ),
        const SizedBox(height: 20),
        AnimatedBuilder(
          animation: animation,
          builder: (context, child) =>
              Opacity(opacity: .4 + animation.value * .4, child: child),
          child: Column(
            children: List.generate(
              3,
              (_) => panel(
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 110,
                      height: 14,
                      decoration: BoxDecoration(
                        color: steel.withValues(alpha: .12),
                        borderRadius: BorderRadius.circular(6),
                      ),
                    ),
                    const SizedBox(height: 16),
                    for (var i = 0; i < 2; i++)
                      Container(
                        height: 48,
                        margin: const EdgeInsets.only(bottom: 12),
                        decoration: BoxDecoration(
                          color: canvas,
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    ),
  );
}

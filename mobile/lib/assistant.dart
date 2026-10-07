import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';

import 'api.dart';
import 'relation_field.dart';
import 'ui.dart';
import 'attachments.dart';
import 'projects.dart';
import 'quotation.dart';

class AssistantPage extends StatefulWidget {
  final PalantirApi api;
  const AssistantPage({super.key, required this.api});
  @override
  State<AssistantPage> createState() => _AssistantPageState();
}

class _AssistantPageState extends State<AssistantPage> {
  final input = TextEditingController();
  final scroll = ScrollController();
  List<Map<String, dynamic>> messages = [], conversations = [];
  String? conversation, error;
  bool sending = false, loading = true;
  @override
  void initState() {
    super.initState();
    input.text = widget.api.assistantState["draft"] ?? "";
    messages = maps(widget.api.assistantState["messages"]);
    conversation = widget.api.assistantState["conversation"];
    load();
  }

  @override
  void dispose() {
    if (widget.api.user != null) {
      widget.api.assistantState.addAll({
        "draft": input.text,
        "messages": messages,
        "conversation": conversation,
      });
    }
    input.dispose();
    scroll.dispose();
    super.dispose();
  }

  Future<void> load() async {
    try {
      final result = await widget.api.page('/ai');
      if (mounted) {
        setState(() => conversations = maps(result['conversations']));
      }
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> send() async {
    final draft = input.text.trim();
    if (draft.isEmpty || sending) return;
    setState(() {
      sending = true;
      error = null;
    });
    try {
      if (draft.contains('报价单')) {
        await sendQuotation(draft);
        return;
      }
      final response = await widget.api.send('/ai/messages', {
        'message': draft,
        if (conversation != null) 'conversation_id': conversation,
      });
      if (!mounted) return;
      setState(() {
        messages.add({'role': 'user', 'content': draft});
        messages.add({
          'role': 'assistant',
          'content': response['answer'],
          'table': response['table'],
          'sources': response['sources'],
        });
        conversation = response['conversation_id']?.toString();
        conversations = maps(response['conversations']);
        input.clear();
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (scroll.hasClients) {
          scroll.animateTo(
            scroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 250),
            curve: Curves.easeOut,
          );
        }
      });
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  Future<void> sendQuotation(String draft) async {
    final bytes = List.generate(16, (_) => Random.secure().nextInt(256));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    final uuid =
        '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
    final response = await widget.api.send('/ai/runs', {
      'message': draft,
      'client_request_id': uuid,
      if (conversation != null) 'conversation_id': conversation,
    });
    if (!mounted) return;
    var run = mapOf(response['run']);
    setState(() {
      conversation = response['conversation_id']?.toString();
      messages.add({'role': 'user', 'content': draft});
      input.clear();
    });
    for (var attempt = 0; attempt < 90 && mounted; attempt++) {
      if (['completed', 'failed', 'cancelled'].contains(run['status'])) break;
      await Future<void>.delayed(const Duration(seconds: 2));
      if (!mounted) return;
      run = mapOf((await widget.api.get('/ai/runs/${run['id']}'))['run']);
    }
    if (!mounted) return;
    if (run['status'] != 'completed') {
      throw ApiFailure('报价任务尚未完成或执行失败，请从历史会话查看结果。');
    }
    setState(
      () => messages.add({
        'role': 'assistant',
        'content': run['answer'],
        'run_id': run['id'],
        'artifacts': run['artifacts'],
      }),
    );
    await load();
  }

  void quickQuotation() {
    input.text = '做个报价单\n产品名称、综合单价及计价单位：\n备注：含税13%含运费';
  }

  Future<void> history() async {
    final id = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (c) => SafeArea(
        child: SizedBox(
          height: 440,
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              const Text(
                '历史会话',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
              ),
              if (conversations.isEmpty) note('暂无历史会话。'),
              ...conversations.map(
                (r) => ListTile(
                  leading: const Icon(Icons.chat_bubble_outline),
                  title: Text(textOf(r['title'])),
                  onTap: () => Navigator.pop(c, r['id'].toString()),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    if (id == null || !mounted) return;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result = await widget.api.get('/ai/conversations/$id');
      if (mounted) {
        setState(() {
          conversation = id;
          messages = maps(result['messages']);
          for (final run in maps(result['runs'])) {
            if (maps(run['artifacts'])
                .any((a) => a['type'] == 'quotation_docx')) {
              messages.add({
                'role': 'assistant',
                'content': '',
                'run_id': run['id'],
                'artifacts': run['artifacts'],
              });
            }
          }
        });
      }
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('AI 助手'),
      actions: [
        IconButton(
          tooltip: '快速制作报价单',
          onPressed: sending ? null : quickQuotation,
          icon: const Icon(Icons.request_quote_outlined),
        ),
        IconButton(
          tooltip: '新会话',
          onPressed: sending
              ? null
              : () => setState(() {
                  conversation = null;
                  messages = [];
                  error = null;
                }),
          icon: const Icon(Icons.add_comment_outlined),
        ),
        IconButton(
          tooltip: '历史会话',
          onPressed: sending ? null : history,
          icon: const Icon(Icons.history),
        ),
      ],
    ),
    body: SafeArea(
      child: Column(
        children: [
          Expanded(
            child: ListView(
              controller: scroll,
              padding: const EdgeInsets.all(20),
              children: [
                if (loading) const LinearProgressIndicator(),
                if (messages.isEmpty) ...[
                  const Icon(
                    Icons.auto_awesome_outlined,
                    size: 40,
                    color: steel,
                  ),
                  const SizedBox(height: 20),
                  const Text(
                    '今天想了解哪些业务进展？',
                    style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
                  ),
                  note('查项目、看合同、核对未回款。回答基于当前账号可访问的数据。'),
                  for (final question in [
                    '我有哪些项目需要跟进？',
                    '按项目列出未回款金额',
                    '哪些合同还没有签署？',
                    '做个报价单',
                  ])
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: OutlinedButton(
                        onPressed: () {
                          input.text = question;
                        },
                        child: Text(question),
                      ),
                    ),
                ],
                ...messages.map(
                  (m) => Align(
                    alignment: m['role'] == 'user'
                        ? Alignment.centerRight
                        : Alignment.centerLeft,
                    child: Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(16),
                      margin: const EdgeInsets.only(bottom: 14),
                      decoration: BoxDecoration(
                        color: m['role'] == 'user'
                            ? steel.withValues(alpha: .09)
                            : Colors.white,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            m['role'] == 'user' ? '我' : 'AI 助手',
                            style: const TextStyle(
                              color: steel,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          const SizedBox(height: 10),
                          SelectableText(
                            textOf(m['content'] ?? m['answer']),
                            style: const TextStyle(height: 1.65),
                          ),
                          for (final artifact
                              in ((m['artifacts'] as List?) ?? [])
                                  .whereType<Map<String, dynamic>>())
                            if (artifact['type'] == 'quotation_docx')
                              QuotationCard(
                                key: ValueKey(
                                  '${m['run_id']}-${artifact['id']}',
                                ),
                                api: widget.api,
                                runId: '${m['run_id']}',
                                artifact: artifact,
                              ),
                          if (mapOf(m['table']).isNotEmpty)
                            answerTable(mapOf(m['table'])),
                          for (final source in maps(m['sources']))
                            ListTile(
                              contentPadding: EdgeInsets.zero,
                              title: Text(
                                textOf(source['title'] ?? source['label']),
                              ),
                              trailing: const Icon(Icons.chevron_right),
                              onTap: () {
                                final uri = Uri.tryParse(
                                  '${source['url'] ?? ''}',
                                );
                                final id = uri?.queryParameters['record'];
                                if (id != null) {
                                  openFlow(
                                    context,
                                    ProjectDetail(api: widget.api, id: id),
                                  );
                                }
                              },
                            ),
                        ],
                      ),
                    ),
                  ),
                ),
                if (sending) note('正在查询业务数据…'),
                if (error != null) errorBox(error!),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
            decoration: const BoxDecoration(
              color: Colors.white,
              border: Border(top: BorderSide(color: Color(0xffdde4ea))),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (widget.api.canUpload)
                  IconButton(
                    tooltip: '上传合同',
                    onPressed: sending
                        ? null
                        : () => openFlow(
                            context,
                            ContractIntakePage(api: widget.api),
                          ),
                    icon: const Icon(Icons.attach_file),
                  ),
                Expanded(
                  child: TextField(
                    key: const Key('ai-input'),
                    controller: input,
                    minLines: 1,
                    maxLines: 5,
                    maxLength: 2000,
                    enabled: !loading,
                    decoration: const InputDecoration(
                      hintText: '输入问题，或继续追问…',
                      counterText: '',
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                ValueListenableBuilder<TextEditingValue>(
                  valueListenable: input,
                  builder: (context, value, child) => IconButton.filled(
                    key: const Key('ai-send'),
                    tooltip: '发送',
                    onPressed: sending || loading || value.text.trim().isEmpty
                        ? null
                        : send,
                    icon: Icon(
                      sending ? Icons.hourglass_top : Icons.arrow_upward,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    ),
  );
  Widget answerTable(Map<String, dynamic> table) {
    final columns = table['columns'] as List? ?? [],
        rows = table['rows'] as List? ?? [];
    if (columns.isEmpty) return const SizedBox.shrink();
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        columns: columns
            .map((c) => DataColumn(label: Text(textOf(c))))
            .toList(),
        rows: rows
            .map(
              (r) => DataRow(
                cells: List.generate(
                  columns.length,
                  (i) => DataCell(
                    Text(
                      textOf(
                        r is List && i < r.length
                            ? r[i]
                            : r is Map
                            ? r[columns[i] is Map
                                  ? columns[i]['key']
                                  : columns[i]]
                            : null,
                      ),
                    ),
                  ),
                ),
              ),
            )
            .toList(),
      ),
    );
  }
}

class ContractIntakePage extends StatefulWidget {
  final PalantirApi api;
  const ContractIntakePage({super.key, required this.api});
  @override
  State<ContractIntakePage> createState() => _ContractIntakePageState();
}

class _ContractIntakePageState extends State<ContractIntakePage> {
  List<Map<String, dynamic>> intakes = [];
  String? error;
  bool busy = false;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final result = await widget.api.get('/ai/contracts');
      if (mounted) setState(() => intakes = maps(result['intakes']));
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    }
  }

  Future<void> upload() async {
    try {
      final file = await selectUpload();
      if (file == null || !mounted) return;
      setState(() {
        busy = true;
        error = null;
      });
      final result = await widget.api.multipart('/ai/contracts', {
        'file': file,
      });
      if (!mounted) return;
      await push(context, IntakeReviewPage(api: widget.api, initial: result));
      await load();
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('上传合同')),
    body: RefreshIndicator(
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          panel(
            Column(
              children: [
                const Icon(Icons.upload_file_outlined, size: 44, color: steel),
                const SizedBox(height: 18),
                const Text(
                  '先识别，再核对归档',
                  style: TextStyle(fontSize: 23, fontWeight: FontWeight.w600),
                ),
                note('选择 PDF、JPG 或 PNG，单个文件最大 20 MB。识别后选择项目及合同，预览差异并确认后才更新资料。'),
                FilledButton.icon(
                  onPressed: busy ? null : upload,
                  icon: const Icon(Icons.add),
                  label: Text(busy ? '正在上传…' : '选择合同文件'),
                ),
              ],
            ),
          ),
          if (error != null) errorBox(error!),
          const SizedBox(height: 14),
          const Text(
            '最近上传',
            style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
          ),
          if (intakes.isEmpty) note('暂无上传记录。'),
          ...intakes.map(
            (r) => Card(
              child: ListTile(
                leading: const Icon(Icons.description_outlined),
                title: Text(textOf(r['file_name'])),
                subtitle: Text(intakeStatus(r['status'])),
                trailing: const Icon(Icons.chevron_right),
                onTap: () async {
                  await push(
                    context,
                    IntakeReviewPage(api: widget.api, initial: r),
                  );
                  load();
                },
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

String intakeStatus(dynamic s) =>
    const {
      'staged': '等待识别',
      'analyzing': '正在识别',
      'review': '待核对',
      'failed': '识别失败，可重试',
      'confirmed': '已归档',
    }[s] ??
    textOf(s);

class IntakeReviewPage extends StatefulWidget {
  final PalantirApi api;
  final Map<String, dynamic> initial;
  const IntakeReviewPage({super.key, required this.api, required this.initial});
  @override
  State<IntakeReviewPage> createState() => _IntakeReviewPageState();
}

class _IntakeReviewPageState extends State<IntakeReviewPage> {
  late Map<String, dynamic> intake;
  Map<String, dynamic> project = {}, fields = {};
  List<Map<String, dynamic>> candidates = [];
  final controls = <String, TextEditingController>{};
  String? contract, error;
  Map<String, dynamic>? preview;
  Timer? timer;
  bool busy = false;
  bool get pending => ['staged', 'analyzing'].contains(intake['status']);
  @override
  void initState() {
    super.initState();
    intake = widget.initial;
    extract();
    if (pending) poll();
  }

  @override
  void dispose() {
    timer?.cancel();
    for (final c in controls.values) {
      c.dispose();
    }
    super.dispose();
  }

  void extract() {
    if (fields.isEmpty && intake['status'] == 'review') {
      final e = mapOf(intake['extraction']);
      fields = {
        for (final k in ['amount', 'ctype', 'signed_date', 'contract_qty'])
          k: e[k],
      };
    }
  }

  void poll() {
    timer = Timer(const Duration(seconds: 3), () async {
      try {
        final r = await widget.api.get('/ai/contracts/${intake['id']}');
        if (!mounted) return;
        setState(() {
          intake = r;
          extract();
        });
        if (pending) poll();
      } catch (e) {
        if (mounted) setState(() => error = '$e');
      }
    });
  }

  Future<void> chooseProject(List<Map<String, dynamic>> items) async {
    if (items.isEmpty) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final p = await widget.api.get(
        '/ai/contracts/projects/${items.first['id']}',
      );
      if (mounted) {
        setState(() {
          project = p;
          contract = null;
          preview = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> action(String mode) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      if (mode == 'retry') {
        final r = await widget.api.send(
          '/ai/contracts/${intake['id']}/retry',
          {},
        );
        if (mounted) {
          setState(() => intake = r);
          poll();
        }
      } else if (mode == 'preview') {
        final r = await widget.api.send(
          '/ai/contracts/${intake['id']}/preview',
          {
            'project_id': project['id'],
            'contract_id': contract,
            'fields': fields,
            'update_project_amount': false,
            'project_weight': null,
          },
        );
        if (mounted) setState(() => preview = r);
      } else {
        final r = await widget.api.send(
          '/ai/contracts/${intake['id']}/confirm',
          {'token': preview!['token']},
        );
        if (mounted) {
          setState(() {
            intake = {...intake, 'status': 'confirmed', 'result': r};
            preview = null;
          });
        }
      }
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final extraction = mapOf(intake['extraction']);
    return Scaffold(
      appBar: AppBar(title: const Text('核对与归档')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            textOf(intake['file_name']),
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: tag(intakeStatus(intake['status'])),
          ),
          if (pending) ...[
            const LinearProgressIndicator(),
            note('文件已暂存，识别完成后可核对。可以返回，稍后从上传记录继续。'),
          ],
          if (error != null) errorBox(error!),
          if (intake['status'] == 'failed') ...[
            errorBox(textOf(intake['error'])),
            FilledButton(
              onPressed: busy ? null : () => action('retry'),
              child: const Text('重新识别'),
            ),
          ],
          if (intake['status'] == 'review' && preview == null) ...[
            for (final warning in extraction['warnings'] as List? ?? [])
              note(textOf(warning)),
            ExpansionTile(
              title: const Text('识别原文依据'),
              children: (extraction['evidence'] as List? ?? [])
                  .map((e) => note(textOf(e)))
                  .toList(),
            ),
            InlineRelationField(
              api: widget.api,
              label: '选择归档项目',
              enabled: !busy,
              options: {
                'search_url': '/ai/contracts/projects',
                'selectedItems': project.isEmpty
                    ? []
                    : [
                        {
                          ...project,
                          'label': '${project['code']} · ${project['name']}',
                        },
                      ],
              },
              selected: project.isEmpty ? [] : ['${project['id']}'],
              onChanged: chooseProject,
            ),
            if (project.isNotEmpty) ...[
              DropdownButtonFormField<String>(
                key: ValueKey(project['id']),
                initialValue: contract ?? '',
                isExpanded: true,
                decoration: const InputDecoration(labelText: '归档到哪份合同'),
                items: [
                  const DropdownMenuItem(value: '', child: Text('新建一份合同')),
                  ...maps(project['contracts']).map(
                    (c) => DropdownMenuItem(
                      value: c['id'].toString(),
                      child: Text(textOf(c['code'])),
                    ),
                  ),
                ],
                onChanged: busy
                    ? null
                    : (v) => setState(() => contract = v == '' ? null : v),
              ),
              note('已有项目主档合同金额保留；尚未填写时按合同汇总补齐。'),
            ],
            for (final pair in const [
              ['amount', '合同金额（元）'],
              ['ctype', '合同类型'],
              ['signed_date', '签订日期 YYYY-MM-DD'],
              ['contract_qty', '合同数量'],
            ])
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: TextField(
                  controller: controls.putIfAbsent(
                    pair[0],
                    () => TextEditingController(
                      text: fields[pair[0]]?.toString() ?? '',
                    ),
                  ),
                  enabled: !busy,
                  decoration: InputDecoration(labelText: pair[1]),
                  onChanged: (v) => fields[pair[0]] = v.isEmpty ? null : v,
                ),
              ),
            FilledButton(
              onPressed: busy || project.isEmpty
                  ? null
                  : () => action('preview'),
              child: const Text('预览归档差异'),
            ),
          ],
          if (preview != null) ...[
            const SizedBox(height: 20),
            const Text(
              '确认将更新以下内容',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
            ),
            kv('项目', mapOf(preview!['project'])['name']),
            kv('合同', preview!['contract']),
            ...maps(preview!['changes']).map(
              (c) => panel(
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      textOf(c['label']),
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    kv('当前', c['before']),
                    kv('更新为', c['after']),
                  ],
                ),
              ),
            ),
            ...(preview!['effects'] as List? ?? []).map((e) => note(textOf(e))),
            FilledButton(
              onPressed: busy ? null : () => action('confirm'),
              child: Text(busy ? '正在归档…' : '确认归档'),
            ),
            TextButton(
              onPressed: busy ? null : () => setState(() => preview = null),
              child: const Text('返回修改'),
            ),
          ],
          if (intake['status'] == 'confirmed') ...[
            note('合同已归档，确认字段已更新。'),
            FilledButton(
              onPressed: () => openFlow(
                context,
                ProjectDetail(
                  api: widget.api,
                  id: mapOf(intake['result'])['project_id'].toString(),
                ),
              ),
              child: const Text('查看项目'),
            ),
          ],
        ],
      ),
    );
  }
}

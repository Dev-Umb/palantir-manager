import 'dart:math';

import 'package:flutter/material.dart';

import 'api.dart';
import 'attachments.dart';
import 'ui.dart';

const reimbursementTypes = ['差旅交通', '客户招待', '办公采购', '其他'];
const reimbursementStatuses = {
  'pending': '审批中',
  'approved': '审批通过',
  'rejected': '已驳回',
};
int? reimbursementCents(String value) {
  if (!RegExp(r'^(?:0|[1-9]\d{0,6})(\.\d{1,2})?$').hasMatch(value)) return null;
  final parts = value.split('.');
  final cents =
      int.parse(parts[0]) * 100 +
      int.parse(parts.length == 1 ? '0' : parts[1].padRight(2, '0'));
  return cents > 0 ? cents : null;
}

String reimbursementDate(dynamic value) {
  final date = DateTime.tryParse('$value')?.toLocal();
  if (date == null) return '—';
  String pad(int part) => part.toString().padLeft(2, '0');
  return '${date.year}-${pad(date.month)}-${pad(date.day)} ${pad(date.hour)}:${pad(date.minute)}';
}

String reimbursementKey() {
  final random = Random.secure();
  final bytes = List.generate(16, (_) => random.nextInt(256));
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  final hex = bytes.map((v) => v.toRadixString(16).padLeft(2, '0')).join();
  return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
}

String reimbursementMoney(dynamic cents) =>
    ((cents as num? ?? 0) / 100).toStringAsFixed(2);

class ReimbursementsPage extends StatefulWidget {
  final PalantirApi api;
  const ReimbursementsPage({super.key, required this.api});
  @override
  State<ReimbursementsPage> createState() => _ReimbursementsPageState();
}

class _ReimbursementsPageState extends State<ReimbursementsPage> {
  String scope = 'mine', status = '', type = '';
  int revision = 0;
  Future<void> open(Map<String, dynamic> r) async {
    await push(context, ReimbursementDetail(api: widget.api, path: r['url']));
    if (mounted) setState(() => revision++);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('报销 OA'),
      actions: [
        IconButton(
          tooltip: '报销通知',
          icon: const Icon(Icons.notifications_outlined),
          onPressed: () => push(context, ReimbursementNotices(api: widget.api)),
        ),
      ],
    ),
    body: Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            children: [
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'mine', label: Text('我的报销')),
                  ButtonSegment(value: 'cc', label: Text('知会我的')),
                ],
                selected: {scope},
                onSelectionChanged: (v) => setState(() {
                  scope = v.first;
                  revision++;
                }),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      initialValue: status,
                      decoration: const InputDecoration(labelText: '状态'),
                      items: [
                        const DropdownMenuItem(value: '', child: Text('全部状态')),
                        ...reimbursementStatuses.entries.map(
                          (e) => DropdownMenuItem(
                            value: e.key,
                            child: Text(e.value),
                          ),
                        ),
                      ],
                      onChanged: (v) => setState(() {
                        status = v!;
                        revision++;
                      }),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      initialValue: type,
                      decoration: const InputDecoration(labelText: '类型'),
                      items: [
                        const DropdownMenuItem(value: '', child: Text('全部类型')),
                        ...reimbursementTypes.map(
                          (t) => DropdownMenuItem(value: t, child: Text(t)),
                        ),
                      ],
                      onChanged: (v) => setState(() {
                        type = v!;
                        revision++;
                      }),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
        Expanded(
          child: ReimbursementList(
            key: ValueKey('$scope-$revision'),
            api: widget.api,
            path:
                '/reimbursements?${Uri(queryParameters: {'scope': scope, 'status': status, 'type': type}).query}',
            open: open,
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: FutureBuilder<Map<String, dynamic>>(
              future: widget.api.get('/reimbursements/options'),
              builder: (context, snapshot) =>
                  mapOf(snapshot.data?['capabilities'])['submit'] == true
                  ? FilledButton.icon(
                      onPressed: () async {
                        final path = await push<String>(
                          context,
                          ReimbursementForm(api: widget.api),
                        );
                        if (!context.mounted) return;
                        if (path != null) {
                          await push(
                            context,
                            ReimbursementDetail(api: widget.api, path: path),
                          );
                        }
                        if (mounted) setState(() => revision++);
                      },
                      icon: const Icon(Icons.add),
                      label: const Text('发起报销'),
                    )
                  : const SizedBox.shrink(),
            ),
          ),
        ),
      ],
    ),
  );
}

class ReimbursementList extends StatefulWidget {
  final PalantirApi api;
  final String path;
  final void Function(Map<String, dynamic>) open;
  final bool notices;
  const ReimbursementList({
    super.key,
    required this.api,
    required this.path,
    required this.open,
    this.notices = false,
  });
  @override
  State<ReimbursementList> createState() => _ReimbursementListState();
}

class _ReimbursementListState extends State<ReimbursementList> {
  final rows = <Map<String, dynamic>>[];
  String? next, error;
  bool loading = false;
  @override
  void initState() {
    super.initState();
    next = widget.path;
    load();
  }

  Future<void> load({bool reset = false}) async {
    if (loading) return;
    if (reset) {
      next = widget.path;
      rows.clear();
    }
    if (next == null) return;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final response = await widget.api.get(next!);
      final data = mapOf(response[widget.notices ? 'notices' : 'records']);
      if (!mounted) return;
      setState(() {
        rows.addAll(maps(data['data']));
        next = data['next_page_url'];
      });
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => RefreshIndicator(
    onRefresh: () => load(reset: true),
    child: ListView(
      padding: const EdgeInsets.all(16),
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        for (final r in rows)
          Card(
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: () => widget.open(r),
              child: Padding(
                padding: const EdgeInsets.all(18),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            widget.notices
                                ? (r['read_at'] == null ? '● 未读' : '已读')
                                : 'BX-${r['reimbursement_id']} · V${r['number']}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ),
                        if (!widget.notices)
                          Text(
                            reimbursementStatuses[r['status']] ?? '',
                            style: TextStyle(
                              color: r['status'] == 'rejected'
                                  ? const Color(0xffa84b3a)
                                  : r['status'] == 'approved'
                                  ? const Color(0xff2b7056)
                                  : const Color(0xff94691c),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Text(
                      '${widget.notices ? r['message'] : r['title']}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 6),
                    Text(
                      '${widget.notices ? '' : '${r['type']} · '}${reimbursementDate(r['created_at'])}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    if (!widget.notices) ...[
                      const Divider(height: 28),
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              '¥${reimbursementMoney(r['total_cents'])}',
                              style: Theme.of(context).textTheme.titleLarge
                                  ?.copyWith(fontWeight: FontWeight.w600),
                            ),
                          ),
                          const Text('查看详情'),
                          const Icon(Icons.chevron_right, size: 18),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        if (!loading && rows.isEmpty && error == null) const Text('暂无记录'),
        if (error != null)
          Text(error!, style: const TextStyle(color: Colors.red)),
        if (loading)
          const Center(child: CircularProgressIndicator())
        else if (next != null)
          TextButton(
            onPressed: load,
            child: Text(error == null ? '加载更多' : '重试'),
          ),
      ],
    ),
  );
}

class ReimbursementNotices extends StatefulWidget {
  final PalantirApi api;
  const ReimbursementNotices({super.key, required this.api});
  @override
  State<ReimbursementNotices> createState() => _ReimbursementNoticesState();
}

class _ReimbursementNoticesState extends State<ReimbursementNotices> {
  int revision = 0;
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('报销通知')),
    body: ReimbursementList(
      key: ValueKey(revision),
      api: widget.api,
      path: '/reimbursements/notices',
      notices: true,
      open: (r) async {
        await push(
          context,
          ReimbursementDetail(api: widget.api, path: r['url']),
        );
        if (mounted) setState(() => revision++);
      },
    ),
  );
}

class ReimbursementDetail extends StatefulWidget {
  final PalantirApi api;
  final String path;
  const ReimbursementDetail({super.key, required this.api, required this.path});
  @override
  State<ReimbursementDetail> createState() => _ReimbursementDetailState();
}

class _ReimbursementDetailState extends State<ReimbursementDetail> {
  int revision = 0;
  bool downloading = false;
  Future<void> download(String url, String name, String mime) async {
    if (downloading) return;
    setState(() => downloading = true);
    try {
      final bytes = await widget.api.download(url);
      if (!mounted) return;
      final saved = await android.invokeMethod('saveReimbursementFile', {
        'bytes': bytes,
        'name': name.replaceAll(RegExp(r'[/\\]'), '_'),
        'mime': mime,
      });
      if (mounted) toast(context, saved == false ? '已取消保存' : '已保存发票');
    } catch (e) {
      if (mounted) toast(context, '$e');
    } finally {
      if (mounted) setState(() => downloading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('报销详情')),
    body: AsyncPage(
      key: ValueKey(revision),
      load: () => widget.api.get(widget.path),
      builder: (data) {
        final r = mapOf(data['record']);
        final flow = mapOf(r['flow']);
        return ListView(
          padding: EdgeInsets.fromLTRB(
            16,
            16,
            16,
            16 + MediaQuery.paddingOf(context).bottom,
          ),
          children: [
            panel(
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('申请信息'),
                  const SizedBox(height: 12),
                  Text(
                    r['title'],
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  kv('状态', reimbursementStatuses[r['status']]),
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Text(
                      '¥${reimbursementMoney(r['total_cents'])}',
                      style: Theme.of(context).textTheme.headlineLarge
                          ?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  kv('申请人', mapOf(r['owner'])['name']),
                  kv('类型', r['type']),
                  kv('提交时间', reimbursementDate(r['created_at'])),
                  Text(r['note']),
                  Wrap(
                    children: [
                      for (final v in maps(r['versions']))
                        TextButton(
                          onPressed: () => push(
                            context,
                            ReimbursementDetail(
                              api: widget.api,
                              path: v['url'],
                            ),
                          ),
                          child: Text('V${v['number']}'),
                        ),
                    ],
                  ),
                ],
              ),
            ),
            Card(
              child: ExpansionTile(
                shape: const Border(),
                collapsedShape: const Border(),
                initiallyExpanded: true,
                title: const Text('费用明细'),
                childrenPadding: const EdgeInsets.all(16),
                children: [
                  OutlinedButton.icon(
                    onPressed: downloading
                        ? null
                        : () => download(
                            r['zip_url'],
                            'BX-${r['reimbursement_id']}-V${r['number']}-发票.zip',
                            'application/zip',
                          ),
                    icon: const Icon(Icons.download),
                    label: const Text('一键下载全部发票'),
                  ),
                  for (final item in maps(r['items']))
                    Column(
                      children: [
                        kv(
                          item['description'],
                          '¥${reimbursementMoney(item['cents'])}',
                        ),
                        for (final file in maps(item['attachments']))
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  '${file['name']}\n${file['mime'] == 'application/pdf' ? 'PDF' : '图片'}',
                                ),
                              ),
                              IconButton(
                                tooltip: '预览',
                                onPressed: () => showDialog(
                                  context: context,
                                  builder: (context) => Dialog.fullscreen(
                                    child: AttachmentPage(
                                      api: widget.api,
                                      path: file['preview_url'],
                                      name: file['name'],
                                    ),
                                  ),
                                ),
                                icon: const Icon(Icons.visibility_outlined),
                              ),
                              IconButton(
                                tooltip: '下载',
                                onPressed: downloading
                                    ? null
                                    : () => download(
                                        file['download_url'],
                                        file['name'],
                                        file['mime'],
                                      ),
                                icon: const Icon(Icons.download_outlined),
                              ),
                            ],
                          ),
                      ],
                    ),
                ],
              ),
            ),
            Card(
              child: ExpansionTile(
                shape: const Border(),
                collapsedShape: const Border(),
                initiallyExpanded: true,
                title: const Text('审批进度'),
                childrenPadding: const EdgeInsets.all(16),
                children: [
                  if (r['blocked'] == true)
                    const Text(
                      '当前审批人已失效，请联系管理员。',
                      style: TextStyle(color: Colors.red),
                    ),
                  for (final (i, node) in maps(flow['nodes']).indexed)
                    ListTile(
                      title: Text(
                        '${i + 1}. ${node['name']} · ${node['mode'] == 'all' ? '会签' : '或签'}',
                      ),
                      subtitle: Text(
                        '${maps(node['people']).map((p) => p['name']).join('、')}\n${i < r['node_index']
                            ? '已通过'
                            : i == r['node_index']
                            ? reimbursementStatuses[r['status']]
                            : '待处理'}\n${maps(r['decisions']).where((d) => d['node'] == i).map((d) => '${d['name']}：${d['decision'] == 'approve' ? '同意' : '驳回'} ${d['note']}').join('\n')}',
                      ),
                    ),
                ],
              ),
            ),
            Card(
              child: ExpansionTile(
                shape: const Border(),
                collapsedShape: const Border(),
                initiallyExpanded: true,
                title: const Text('知会人员'),
                children: [
                  ListTile(
                    title: Text(
                      '固定：${maps(flow['cc']).map((p) => p['name']).join('、')}\n追加：${maps(r['cc']).map((p) => p['name']).join('、')}',
                    ),
                  ),
                ],
              ),
            ),
            if (r['can_resubmit'] == true)
              FilledButton(
                onPressed: () async {
                  final path = await push<String>(
                    context,
                    ReimbursementForm(api: widget.api, previous: r),
                  );
                  if (!context.mounted) return;
                  if (path != null) {
                    await push(
                      context,
                      ReimbursementDetail(api: widget.api, path: path),
                    );
                  }
                  if (mounted) setState(() => revision++);
                },
                child: const Text('修改并重新提交'),
              ),
            if (r['status'] == 'approved') const Text('审批通过不代表已付款。'),
          ],
        );
      },
    ),
  );
}

class ReimbursementForm extends StatefulWidget {
  final PalantirApi api;
  final Map<String, dynamic>? previous;
  const ReimbursementForm({super.key, required this.api, this.previous});
  @override
  State<ReimbursementForm> createState() => _ReimbursementFormState();
}

class _ReimbursementFormState extends State<ReimbursementForm> {
  final form = GlobalKey<FormState>();
  final title = TextEditingController(), note = TextEditingController();
  final rows = <Map<String, dynamic>>[];
  final cc = <int>{};
  List<Map<String, dynamic>> people = [];
  Map<String, dynamic>? pending;
  String type = reimbursementTypes.first, key = reimbursementKey(), error = '';
  bool busy = false;
  int uploading = 0;
  @override
  void initState() {
    super.initState();
    final old = widget.previous;
    title.text = old?['title'] ?? '';
    note.text = old?['note'] ?? '';
    type = old?['type'] ?? type;
    cc.addAll(maps(old?['cc']).map((p) => p['id'] as int));
    for (final item in maps(old?['items'])) {
      add(item);
    }
    if (rows.isEmpty) add();
    widget.api
        .get('/reimbursements/options')
        .then((o) {
          if (mounted) setState(() => people = maps(o['users']));
        })
        .catchError((Object e) {
          if (mounted) setState(() => error = '$e');
        });
  }

  void add([Map<String, dynamic>? item]) {
    rows.add({
      'key': reimbursementKey(),
      'description': TextEditingController(text: item?['description'] ?? ''),
      'amount': TextEditingController(text: item?['amount'] ?? ''),
      'attachments': [...maps(item?['attachments'])],
      'failed': <UploadFile>[],
    });
  }

  @override
  void dispose() {
    title.dispose();
    note.dispose();
    for (final r in rows) {
      r['description'].dispose();
      r['amount'].dispose();
    }
    super.dispose();
  }

  Future<void> upload(Map<String, dynamic> row, [UploadFile? retry]) async {
    if (busy || pending != null || uploading > 0) return;
    try {
      final file = retry ?? await selectUpload();
      if (file == null || !mounted) return;
      if (file.bytes.length > 20 * 1024 * 1024 ||
          !RegExp(
            r'\.(pdf|jpe?g|png)$',
            caseSensitive: false,
          ).hasMatch(file.name)) {
        throw ApiFailure('请选择 20 MB 以内的 PDF、JPG 或 PNG');
      }
      setState(() {
        uploading++;
        error = '';
      });
      try {
        final result = await widget.api.multipart('/reimbursements/uploads', {
          'file': file,
        });
        if (mounted) {
          setState(() {
            row['attachments'].add(result['file']);
            row['failed'].remove(file);
          });
        }
      } catch (e) {
        if (mounted) {
          setState(() {
            if (!row['failed'].contains(file)) row['failed'].add(file);
            error = '$e';
          });
        }
      } finally {
        if (mounted) setState(() => uploading--);
      }
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    }
  }

  Future<void> submit() async {
    if (busy || uploading > 0 || !form.currentState!.validate()) return;
    if (rows.any((r) => r['attachments'].isEmpty || r['failed'].isNotEmpty)) {
      setState(() => error = '每项费用至少上传一张发票，请重试或移除失败附件。');
      return;
    }
    setState(() {
      busy = true;
      error = '';
    });
    try {
      pending ??= {
        'submission_key': key,
        'title': title.text.trim(),
        'note': note.text.trim(),
        'type': type,
        'cc': cc.toList(),
        'items': rows
            .map(
              (r) => {
                'description': r['description'].text.trim(),
                'amount': r['amount'].text,
                'attachments': (r['attachments'] as List)
                    .map((a) => a['id'])
                    .toList(),
              },
            )
            .toList(),
        if (widget.previous != null) ...{
          'reimbursement_id': widget.previous!['reimbursement_id'],
          'expected_version': widget.previous!['number'],
        },
      };
      final result = await widget.api.send('/reimbursements', pending!);
      if (mounted) {
        Navigator.of(context).pop(result['record']['url'] as String);
      }
    } catch (e) {
      if (e is ApiFailure && e.status >= 400 && e.status < 500) {
        pending = null;
        key = reimbursementKey();
      }
      if (mounted) {
        setState(
          () => error = '$e${pending != null ? '；再次提交将重试同一申请，填写内容暂时锁定。' : ''}',
        );
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final total = rows.fold<int>(
      0,
      (v, r) => v + (reimbursementCents(r['amount'].text) ?? 0),
    );
    return Scaffold(
      appBar: AppBar(title: Text(widget.previous == null ? '发起报销' : '修改重提')),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  '合计 ¥${reimbursementMoney(total)}',
                  style: const TextStyle(fontSize: 20),
                ),
              ),
              FilledButton(
                onPressed: busy || uploading > 0 ? null : submit,
                child: Text(busy ? '提交中…' : '提交申请'),
              ),
            ],
          ),
        ),
      ),
      body: Form(
        key: form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            panel(
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('申请信息', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: title,
                    enabled: !busy && pending == null,
                    maxLength: 120,
                    decoration: const InputDecoration(labelText: '报销标题'),
                    validator: (v) => v!.trim().isEmpty ? '请填写标题' : null,
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<String>(
                    initialValue: type,
                    decoration: const InputDecoration(labelText: '报销类型'),
                    items: reimbursementTypes
                        .map((t) => DropdownMenuItem(value: t, child: Text(t)))
                        .toList(),
                    onChanged: busy || pending != null
                        ? null
                        : (v) => setState(() => type = v!),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: note,
                    enabled: !busy && pending == null,
                    maxLength: 2000,
                    maxLines: 3,
                    decoration: const InputDecoration(labelText: '报销说明'),
                    validator: (v) => v!.trim().isEmpty ? '请填写说明' : null,
                  ),
                ],
              ),
            ),
            for (final (i, r) in rows.indexed)
              Card(
                key: ValueKey(r['key']),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Expanded(child: Text('费用 ${i + 1}')),
                          IconButton(
                            tooltip: '删除费用',
                            onPressed:
                                busy ||
                                    pending != null ||
                                    uploading > 0 ||
                                    rows.length == 1
                                ? null
                                : () => setState(() {
                                    rows.removeAt(i);
                                    r['description'].dispose();
                                    r['amount'].dispose();
                                  }),
                            icon: const Icon(Icons.delete_outline),
                          ),
                        ],
                      ),
                      TextFormField(
                        controller: r['description'],
                        enabled: !busy && pending == null,
                        maxLength: 500,
                        decoration: const InputDecoration(labelText: '费用说明'),
                        validator: (v) => v!.trim().isEmpty ? '请填写费用说明' : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: r['amount'],
                        enabled: !busy && pending == null,
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        decoration: const InputDecoration(labelText: '金额（元）'),
                        validator: (v) => reimbursementCents(v ?? '') == null
                            ? '请输入大于零、最多两位小数的金额'
                            : null,
                        onChanged: (_) => setState(() {}),
                      ),
                      for (final a in [...r['attachments']])
                        ListTile(
                          title: Text(a['name']),
                          subtitle: const Text('上传成功'),
                          trailing: IconButton(
                            tooltip: '移除附件',
                            onPressed: busy || pending != null || uploading > 0
                                ? null
                                : () => setState(
                                    () => r['attachments'].remove(a),
                                  ),
                            icon: const Icon(Icons.close),
                          ),
                        ),
                      for (final f in [...r['failed']])
                        ListTile(
                          title: Text('${f.name} · 上传失败'),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              TextButton(
                                onPressed: uploading > 0
                                    ? null
                                    : () => upload(r, f),
                                child: const Text('重试'),
                              ),
                              IconButton(
                                onPressed: () =>
                                    setState(() => r['failed'].remove(f)),
                                icon: const Icon(Icons.close),
                              ),
                            ],
                          ),
                        ),
                      OutlinedButton.icon(
                        onPressed:
                            busy ||
                                pending != null ||
                                uploading > 0 ||
                                r['attachments'].length >= 10
                            ? null
                            : () => upload(r),
                        icon: const Icon(Icons.attach_file),
                        label: Text(uploading > 0 ? '上传中…' : '添加发票（图片 / PDF）'),
                      ),
                    ],
                  ),
                ),
              ),
            TextButton.icon(
              onPressed:
                  busy || pending != null || uploading > 0 || rows.length >= 50
                  ? null
                  : () => setState(() => add()),
              icon: const Icon(Icons.add),
              label: const Text('添加费用'),
            ),
            Card(
              child: ExpansionTile(
                shape: const Border(),
                collapsedShape: const Border(),
                title: Text('追加知会人（${cc.length}）'),
                children: [
                  for (final p in people)
                    CheckboxListTile(
                      title: Text(p['name']),
                      value: cc.contains(p['id']),
                      onChanged: busy || pending != null
                          ? null
                          : (v) => setState(() {
                              if (v!) {
                                cc.add(p['id']);
                              } else {
                                cc.remove(p['id']);
                              }
                            }),
                    ),
                ],
              ),
            ),
            if (error.isNotEmpty)
              Text(error, style: const TextStyle(color: Colors.red)),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }
}

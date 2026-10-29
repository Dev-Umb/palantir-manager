import 'dart:math';
import 'dart:async';
import 'dart:convert';

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

bool validInvoiceDate(String value) {
  if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(value)) return false;
  final date = DateTime.tryParse(value);
  return date != null && date.toIso8601String().substring(0, 10) == value;
}

String invoiceDescription(String previous, Map<String, dynamic> fields) {
  final lines = previous.trim().isEmpty
      ? <String>[]
      : previous.trim().split('\n');
  for (final field in {
    'invoice_number': '发票号码',
    'invoice_code': '发票代码',
    'buyer': '购买方',
    'seller': '销售方',
  }.entries) {
    final value = fields[field.key];
    if (value is! String || value.trim().isEmpty) continue;
    final line = '${field.value}：${value.trim()}';
    if (!lines.contains(line)) lines.add(line);
  }
  return lines.join('\n');
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
    if (scope == 'drafts') {
      await push(context, ReimbursementForm(api: widget.api, draftId: r['id']));
    } else {
      await push(context, ReimbursementDetail(api: widget.api, path: r['url']));
    }
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
                  ButtonSegment(value: 'drafts', label: Text('我的草稿')),
                ],
                selected: {scope},
                onSelectionChanged: (v) => setState(() {
                  scope = v.first;
                  revision++;
                }),
              ),
              const SizedBox(height: 12),
              if (scope != 'drafts')
                Row(
                  children: [
                    Expanded(
                      child: DropdownButtonFormField<String>(
                        initialValue: status,
                        decoration: const InputDecoration(labelText: '状态'),
                        items: [
                          const DropdownMenuItem(
                            value: '',
                            child: Text('全部状态'),
                          ),
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
                          const DropdownMenuItem(
                            value: '',
                            child: Text('全部类型'),
                          ),
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
            path: scope == 'drafts'
                ? '/reimbursements/drafts'
                : '/reimbursements?${Uri(queryParameters: {'scope': scope, 'status': status, 'type': type}).query}',
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
        rows.addAll(
          maps(data['data']).map((r) {
            if (!widget.path.startsWith('/reimbursements/drafts')) return r;
            final payload = mapOf(r['payload']);
            return {
              ...r,
              'is_draft': true,
              'title': (payload['title'] as String? ?? '').isEmpty
                  ? '未命名报销'
                  : payload['title'],
              'type': payload['type'] ?? '未选择类型',
              'created_at': r['updated_at'],
              'total_cents': maps(payload['items']).fold<int>(
                0,
                (sum, i) => sum + (reimbursementCents(i['amount'] ?? '') ?? 0),
              ),
            };
          }),
        );
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
                                : r['is_draft'] == true
                                ? '未提交 · 仅自己可见'
                                : 'BX-${r['reimbursement_id']} · V${r['number']}',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ),
                        if (!widget.notices)
                          Text(
                            r['is_draft'] == true
                                ? '草稿'
                                : reimbursementStatuses[r['status']] ?? '',
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
                          Text(r['is_draft'] == true ? '继续编辑' : '查看详情'),
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
                        kv('开票日期', item['invoice_date'] ?? '未填写'),
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
  final String? draftId;
  const ReimbursementForm({
    super.key,
    required this.api,
    this.previous,
    this.draftId,
  });
  @override
  State<ReimbursementForm> createState() => _ReimbursementFormState();
}

class _ReimbursementFormState extends State<ReimbursementForm>
    with WidgetsBindingObserver {
  final form = GlobalKey<FormState>();
  final title = TextEditingController(), note = TextEditingController();
  final rows = <Map<String, dynamic>>[];
  final cc = <int>{};
  List<Map<String, dynamic>> people = [];
  Map<String, dynamic>? pending;
  String type = reimbursementTypes.first, key = reimbursementKey(), error = '';
  bool busy = false;
  int uploading = 0;
  Future<void> invoiceReviews = Future.value();
  String draftId = reimbursementKey(), draftStatus = '';
  int draftRevision = 0;
  bool restoring = true,
      draftConflict = false,
      disposed = false,
      leaveAllowed = false;
  bool draftLoadFailed = false;
  Object? account;
  String? savedPayload;
  Map<String, dynamic>? saveRequest, previous;
  Future<void>? draftSaving;
  Timer? draftTimer;
  final uploadContexts = <UploadFile, String>{};
  Map<String, dynamic> draftPayload() => {
    'title': title.text,
    'note': note.text,
    'type': type,
    'cc': cc.toList(),
    'items': rows
        .map(
          (r) => {
            'description': r['description'].text,
            'amount': r['amount'].text,
            'invoice_date': r['invoice_date'].text.isEmpty
                ? null
                : r['invoice_date'].text,
            'attachments': (r['attachments'] as List)
                .map((a) => a['id'])
                .toList(),
          },
        )
        .toList(),
    if (previous != null) 'reimbursement_id': previous!['reimbursement_id'],
    if (previous != null) 'expected_version': previous!['number'],
  };
  void scheduleDraft() {
    if (disposed ||
        restoring ||
        draftRevision == 0 ||
        pending != null ||
        draftConflict ||
        widget.api.user?['id'] != account) {
      return;
    }
    if (savedPayload == jsonEncode(draftPayload()) && saveRequest == null) {
      return;
    }
    if (draftStatus != '待保存' && draftSaving == null) {
      super.setState(() => draftStatus = '待保存');
    }
    draftTimer?.cancel();
    draftTimer = Timer(const Duration(milliseconds: 1200), () {
      flushDraft().catchError((Object _) {});
    });
  }

  Future<void> flushDraft({bool silent = false}) async {
    draftTimer?.cancel();
    if (disposed ||
        restoring ||
        draftRevision == 0 ||
        pending != null ||
        uploading > 0 ||
        widget.api.user?['id'] != account) {
      return;
    }
    if (draftConflict) throw ApiFailure('草稿有更新冲突，请重新打开草稿');
    if (draftSaving != null) {
      await draftSaving;
      return flushDraft();
    }
    final payload = draftPayload();
    if (savedPayload == jsonEncode(payload) && saveRequest == null) return;
    saveRequest ??= {'revision': draftRevision, 'payload': payload};
    final request = saveRequest!;
    if (!silent) setState(() => draftStatus = '保存中…');
    draftSaving = () async {
      try {
        final result = await widget.api.send(
          '/reimbursements/drafts/$draftId',
          request,
          method: 'PUT',
        );
        if (disposed || widget.api.user?['id'] != account) return;
        draftRevision = result['draft']['revision'];
        savedPayload = jsonEncode(request['payload']);
        saveRequest = null;
        setState(() => draftStatus = '已保存');
      } catch (e) {
        if (!disposed && widget.api.user?['id'] == account) {
          setState(() {
            draftStatus = '保存失败，点击重试';
            draftConflict = e is ApiFailure && e.status == 409;
            error = '$e';
          });
        }
        rethrow;
      } finally {
        draftSaving = null;
      }
    }();
    await draftSaving;
    if (!disposed &&
        widget.api.user?['id'] == account &&
        savedPayload != jsonEncode(draftPayload())) {
      await flushDraft();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      flushDraft().catchError((Object _) {});
    }
  }

  @override
  void setState(VoidCallback fn) {
    final before = disposed || restoring ? null : jsonEncode(draftPayload());
    super.setState(fn);
    if (before != null && before != jsonEncode(draftPayload())) scheduleDraft();
  }

  Future<void> leaveForm() async {
    if (uploading > 0 || busy) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('正在保存，请稍候再返回')));
      return;
    }
    try {
      await flushDraft();
    } catch (_) {
      if (!mounted) return;
      final leave = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('修改尚未保存'),
          content: const Text('离开可能丢失最新修改，已保存的草稿仍会保留。'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('继续编辑'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('仍然离开'),
            ),
          ],
        ),
      );
      if (leave != true) return;
    }
    if (!mounted) return;
    setState(() => leaveAllowed = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) Navigator.pop(context);
    });
  }

  Future<void> loadDrafts() async {
    restoring = true;
    draftLoadFailed = false;
    try {
      String? selected = draftRevision > 0 ? draftId : widget.draftId;
      if (selected == null && previous == null) {
        final response = await widget.api.get('/reimbursements/drafts');
        if (!mounted || widget.api.user?['id'] != account) return;
        final drafts = maps(mapOf(response['records'])['data']);
        if (drafts.isNotEmpty) {
          final latest = drafts.first;
          final choice = await showDialog<bool>(
            context: context,
            barrierDismissible: false,
            builder: (context) => AlertDialog(
              title: const Text('有未完成的报销草稿'),
              content: Text(
                '${mapOf(latest['payload'])['title'] ?? '未命名报销'}\n保存于 ${reimbursementDate(latest['updated_at'])}',
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(context, false),
                  child: const Text('新建报销'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(context, true),
                  child: const Text('继续编辑'),
                ),
              ],
            ),
          );
          if (choice == true) selected = latest['id'];
        }
      }
      if (selected != null) {
        final result = await widget.api.get('/reimbursements/drafts/$selected');
        if (!mounted || widget.api.user?['id'] != account) return;
        final draft = mapOf(result['draft']), data = mapOf(draft['payload']);
        if (draft['source_version_id'] != null) {
          final source = await widget.api.get(
            '/reimbursements/versions/${draft['source_version_id']}',
          );
          if (!mounted || widget.api.user?['id'] != account) return;
          previous = mapOf(source['record']);
          if (previous!['can_resubmit'] != true) {
            throw ApiFailure('原申请已变化，请从报销清单重新打开');
          }
        }
        draftId = draft['id'];
        draftRevision = draft['revision'];
        title.text = data['title'] ?? '';
        note.text = data['note'] ?? '';
        type = data['type'] ?? reimbursementTypes.first;
        cc
          ..clear()
          ..addAll((data['cc'] as List).cast<int>());
        for (final r in rows) {
          r['description'].dispose();
          r['amount'].dispose();
          r['invoice_date'].dispose();
        }
        rows.clear();
        for (final item in maps(data['items'])) {
          add(item);
        }
        savedPayload = jsonEncode(draftPayload());
        draftStatus = '已保存';
      }
    } catch (e) {
      draftLoadFailed = true;
      if (mounted) error = '$e';
    } finally {
      restoring = false;
      if (mounted) setState(() {});
    }
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    account = widget.api.user?['id'];
    previous = widget.previous;
    final old = previous;
    title.text = old?['title'] ?? '';
    note.text = old?['note'] ?? '';
    type = old?['type'] ?? type;
    cc.addAll(maps(old?['cc']).map((p) => p['id'] as int));
    for (final item in maps(old?['items'])) {
      add(item);
    }
    if (rows.isEmpty) add();
    title.addListener(scheduleDraft);
    note.addListener(scheduleDraft);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) loadDrafts();
    });
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
      'invoice_date': TextEditingController(text: item?['invoice_date'] ?? ''),
      'attachments': [...maps(item?['attachments'])],
      'failed': <UploadFile>[],
    });
    for (final field in ['description', 'amount', 'invoice_date']) {
      (rows.last[field] as TextEditingController).addListener(scheduleDraft);
    }
  }

  @override
  void dispose() {
    flushDraft(silent: true).catchError((Object _) {});
    disposed = true;
    draftTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    title.dispose();
    note.dispose();
    for (final r in rows) {
      r['description'].dispose();
      r['amount'].dispose();
      r['invoice_date'].dispose();
    }
    super.dispose();
  }

  Future<void> recognizeInvoice(
    Map<String, dynamic> row,
    Map<String, dynamic> attachment,
  ) async {
    if (busy || pending != null || attachment['recognizing'] == true) return;
    final account = widget.api.user?['id'];
    bool current() =>
        mounted &&
        widget.api.user?['id'] == account &&
        rows.contains(row) &&
        row['attachments'].contains(attachment);
    setState(() {
      attachment['recognizing'] = true;
      attachment.remove('recognitionError');
    });
    try {
      final result = await widget.api.send(
        '/reimbursements/uploads/${attachment['id']}/recognize',
        {},
      );
      if (current() && !busy && pending == null) {
        setState(() {
          attachment['recognition'] = result;
          if (!mapOf(result['fields']).values.any((value) => value != null)) {
            attachment['recognitionError'] = '未识别出可填写信息，请手动填写或重新识别。';
          }
        });
        invoiceReviews = invoiceReviews.then((_) async {
          if (current() && !busy && pending == null) {
            await applyInvoiceAmount(row, attachment);
          }
        });
      }
    } catch (_) {
      if (current() && !busy && pending == null) {
        setState(
          () => attachment['recognitionError'] = '识别未完成，可重试或手动填写；附件已保留。',
        );
      }
    } finally {
      if (current()) setState(() => attachment['recognizing'] = false);
    }
  }

  Future<void> applyInvoiceAmount(
    Map<String, dynamic> row,
    Map<String, dynamic> attachment,
  ) async {
    final fields = mapOf(mapOf(attachment['recognition'])['fields']);
    if (!fields.values.any((value) => value != null) ||
        busy ||
        pending != null) {
      return;
    }
    final account = widget.api.user?['id'];
    final amount = row['amount'] as TextEditingController;
    final date = row['invoice_date'] as TextEditingController;
    final description = row['description'] as TextEditingController;
    final previous = amount.text,
        previousDate = date.text,
        previousDescription = description.text;
    final cents = fields['total_cents'];
    final nextAmount = cents is int && cents > 0
        ? reimbursementMoney(cents)
        : previous;
    final nextDate = validInvoiceDate('${fields['invoice_date']}')
        ? fields['invoice_date'] as String
        : previousDate;
    final nextDescription = invoiceDescription(previousDescription, fields);
    if (nextDescription.runes.length > 500) {
      setState(() => error = '回填后费用说明超过 500 字，请先整理说明再重试。');
      return;
    }
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('核对发票信息'),
        content: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                '金额（元）',
                style: TextStyle(fontSize: 13, color: Color(0xff738297)),
              ),
              Text(
                nextAmount.isEmpty ? '未识别' : nextAmount,
                style: const TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w600,
                ),
              ),
              if (previous.isNotEmpty && previous != nextAmount)
                Text('原金额 $previous 元', style: const TextStyle(fontSize: 12)),
              const SizedBox(height: 18),
              const Text(
                '开票日期',
                style: TextStyle(fontSize: 13, color: Color(0xff738297)),
              ),
              Text(
                nextDate.isEmpty ? '未识别' : nextDate,
                style: const TextStyle(fontSize: 16),
              ),
              if (previousDate.isNotEmpty && previousDate != nextDate)
                Text('原日期 $previousDate', style: const TextStyle(fontSize: 12)),
              const SizedBox(height: 18),
              const Text(
                '费用说明',
                style: TextStyle(fontSize: 13, color: Color(0xff738297)),
              ),
              const SizedBox(height: 8),
              Text(
                nextDescription.isEmpty ? '未识别，可手动填写' : nextDescription,
                style: const TextStyle(fontSize: 15, height: 1.65),
              ),
              const SizedBox(height: 18),
              const Text(
                '请对照原件核对，填入后仍可修改。仅填入本张发票，不累加其他费用。',
                style: TextStyle(
                  fontSize: 12,
                  height: 1.6,
                  color: Color(0xff738297),
                ),
              ),
              for (final warning
                  in (mapOf(attachment['recognition'])['warnings'] as List? ??
                      []))
                Text(
                  '$warning',
                  style: const TextStyle(
                    fontSize: 12,
                    height: 1.6,
                    color: Color(0xff738297),
                  ),
                ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('取消'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('确认填入'),
          ),
        ],
      ),
    );
    if (confirmed == true &&
        mounted &&
        widget.api.user?['id'] == account &&
        !busy &&
        pending == null &&
        rows.contains(row) &&
        row['attachments'].contains(attachment) &&
        amount.text == previous &&
        date.text == previousDate &&
        description.text == previousDescription) {
      setState(() {
        amount.text = nextAmount;
        date.text = nextDate;
        description.text = nextDescription;
      });
    }
  }

  Widget invoiceRecognition(
    Map<String, dynamic> row,
    Map<String, dynamic> attachment,
  ) {
    final result = mapOf(attachment['recognition']);
    final fields = mapOf(result['fields']);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('上传成功'),
        if (attachment['recognizing'] == true) const Text('正在识别发票…可继续填写'),
        if (fields.values.any((value) => value != null))
          TextButton(
            onPressed: busy || pending != null
                ? null
                : () => applyInvoiceAmount(row, attachment),
            child: const Text('核对发票'),
          ),
        if (attachment['recognitionError'] != null)
          Text(attachment['recognitionError']),
        if (attachment['mime'] == 'application/pdf' &&
            attachment['recognizing'] != true)
          TextButton(
            onPressed: busy || pending != null
                ? null
                : () => recognizeInvoice(row, attachment),
            child: Text(result.isEmpty ? '识别发票' : '重新识别'),
          ),
      ],
    );
  }

  Future<void> upload(Map<String, dynamic> row, [UploadFile? retry]) async {
    if (draftLoadFailed ||
        restoring ||
        busy ||
        pending != null ||
        uploading > 0) {
      return;
    }
    try {
      final account = widget.api.user?['id'];
      final file = retry ?? await selectUpload();
      if (file == null ||
          !mounted ||
          widget.api.user?['id'] != account ||
          busy ||
          pending != null ||
          !rows.contains(row)) {
        return;
      }
      if (file.bytes.length > 20 * 1024 * 1024 ||
          !RegExp(
            r'\.(pdf|jpe?g|png)$',
            caseSensitive: false,
          ).hasMatch(file.name)) {
        throw ApiFailure('请选择 20 MB 以内的 PDF、JPG 或 PNG');
      }
      if (retry == null &&
          (row['attachments'].isNotEmpty || row['failed'].isNotEmpty)) {
        if (rows.length >= 50) throw ApiFailure('每张发票独立一项费用，最多添加 50 项。');
        setState(() => add());
        row = rows.last;
      }
      await flushDraft();
      final timer = Stopwatch()..start();
      setState(() {
        uploading++;
        error = '';
      });
      try {
        if (draftRevision > 0 || file.name.toLowerCase().endsWith('.pdf')) {
          uploadContexts.putIfAbsent(
            file,
            () => jsonEncode({
              'id': draftId,
              'revision': draftRevision,
              'operation': reimbursementKey(),
              'item': rows.indexOf(row),
              'payload': draftPayload(),
            }),
          );
        }
        final result = await widget.api.multipart('/reimbursements/uploads', {
          'file': file,
          if (uploadContexts.containsKey(file))
            'draft_context': uploadContexts[file],
        });
        if (mounted &&
            widget.api.user?['id'] == account &&
            rows.contains(row)) {
          debugPrint(
            'reimbursement.upload_timing: total_ms=${timer.elapsedMilliseconds}, server=${result['timing']}',
          );
          setState(() {
            if (result['draft'] != null) {
              draftRevision = result['draft']['revision'];
              savedPayload = null;
              saveRequest = null;
              draftStatus = '已保存';
            }
            row['attachments'].add(result['file']);
            row['failed'].remove(file);
          });
          if (result['file']['mime'] == 'application/pdf') {
            recognizeInvoice(row, result['file']);
          }
        }
      } catch (e) {
        if (mounted &&
            widget.api.user?['id'] == account &&
            rows.contains(row)) {
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
    if (widget.api.user?['id'] != account ||
        draftLoadFailed ||
        restoring ||
        busy ||
        uploading > 0 ||
        !form.currentState!.validate()) {
      return;
    }
    if (rows.any((r) => r['attachments'].isEmpty || r['failed'].isNotEmpty)) {
      setState(() => error = '每项费用至少上传一张发票，请重试或移除失败附件。');
      return;
    }
    setState(() {
      busy = true;
      error = '';
    });
    try {
      if (pending == null) await flushDraft();
      pending ??= {
        'submission_key': key,
        if (draftRevision > 0) ...{
          'draft_id': draftId,
          'draft_revision': draftRevision,
        },
        'title': title.text.trim(),
        'note': note.text.trim(),
        'type': type,
        'cc': cc.toList(),
        'items': rows
            .map(
              (r) => {
                'description': r['description'].text.trim(),
                'amount': r['amount'].text,
                'invoice_date': r['invoice_date'].text.isEmpty
                    ? null
                    : r['invoice_date'].text,
                'attachments': (r['attachments'] as List)
                    .map((a) => a['id'])
                    .toList(),
              },
            )
            .toList(),
        if (previous != null) ...{
          'reimbursement_id': previous!['reimbursement_id'],
          'expected_version': previous!['number'],
        },
      };
      final result = await widget.api.send('/reimbursements', pending!);
      if (mounted) {
        setState(() => leaveAllowed = true);
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) {
            Navigator.of(context).pop(result['record']['url'] as String);
          }
        });
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
    return PopScope<Object?>(
      canPop: leaveAllowed || (draftRevision == 0 && uploading == 0),
      onPopInvokedWithResult: (didPop, result) {
        if (!didPop) leaveForm();
      },
      child: Scaffold(
        appBar: AppBar(title: Text(previous == null ? '发起报销' : '修改重提')),
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
                  onPressed: restoring || busy || uploading > 0 ? null : submit,
                  child: Text(busy ? '提交中…' : '提交申请'),
                ),
              ],
            ),
          ),
        ),
        body: Theme(
          data: Theme.of(context).copyWith(
            inputDecorationTheme: Theme.of(context).inputDecorationTheme
                .copyWith(
                  fillColor: const Color(0xfffafbfd),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10),
                    borderSide: const BorderSide(color: Color(0xffe1e7ee)),
                  ),
                  labelStyle: const TextStyle(
                    fontSize: 14,
                    color: Color(0xff687586),
                  ),
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 12,
                    vertical: 14,
                  ),
                ),
          ),
          child: Form(
            key: form,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (restoring) const Text('正在检查草稿…'),
                if (draftLoadFailed)
                  TextButton(
                    onPressed: loadDrafts,
                    child: const Text('重新读取草稿'),
                  ),
                if (draftStatus.isNotEmpty)
                  TextButton(
                    onPressed: () {
                      flushDraft().catchError((Object _) {});
                    },
                    child: Text('$draftStatus · 仅自己可见'),
                  ),
                if (draftConflict)
                  TextButton(
                    onPressed: () {
                      draftConflict = false;
                      saveRequest = null;
                      loadDrafts();
                    },
                    child: const Text('重新打开最新草稿'),
                  ),
                panel(
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '申请信息',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: title,
                        enabled: !restoring && !busy && pending == null,
                        maxLength: 120,
                        decoration: const InputDecoration(labelText: '报销标题'),
                        validator: (v) => v!.trim().isEmpty ? '请填写标题' : null,
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        initialValue: type,
                        decoration: const InputDecoration(labelText: '报销类型'),
                        items: reimbursementTypes
                            .map(
                              (t) => DropdownMenuItem(value: t, child: Text(t)),
                            )
                            .toList(),
                        onChanged: busy || pending != null
                            ? null
                            : (v) => setState(() => type = v!),
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: note,
                        enabled: !restoring && !busy && pending == null,
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
                                        r['invoice_date'].dispose();
                                      }),
                                icon: const Icon(Icons.delete_outline),
                              ),
                            ],
                          ),
                          TextFormField(
                            controller: r['description'],
                            enabled: !restoring && !busy && pending == null,
                            maxLength: 500,
                            minLines: 3,
                            maxLines: 6,
                            style: const TextStyle(fontSize: 15, height: 1.65),
                            decoration: const InputDecoration(
                              labelText: '费用说明',
                            ),
                            validator: (v) =>
                                v!.trim().isEmpty ? '请填写费用说明' : null,
                          ),
                          const SizedBox(height: 12),
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(
                                child: TextFormField(
                                  controller: r['amount'],
                                  style: const TextStyle(
                                    fontSize: 15,
                                    height: 1.5,
                                  ),
                                  enabled:
                                      !restoring && !busy && pending == null,
                                  keyboardType:
                                      const TextInputType.numberWithOptions(
                                        decimal: true,
                                      ),
                                  decoration: const InputDecoration(
                                    labelText: '金额（元）',
                                  ),
                                  validator: (v) =>
                                      reimbursementCents(v ?? '') == null
                                      ? '请输入大于零、最多两位小数的金额'
                                      : null,
                                  onChanged: (_) => setState(() {}),
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: TextFormField(
                                  controller: r['invoice_date'],
                                  style: const TextStyle(
                                    fontSize: 14,
                                    height: 1.5,
                                  ),
                                  enabled:
                                      !restoring && !busy && pending == null,
                                  keyboardType: TextInputType.datetime,
                                  decoration: const InputDecoration(
                                    labelText: '开票日期',
                                    hintText: 'YYYY-MM-DD',
                                  ),
                                  validator: (value) =>
                                      value == null ||
                                          value.isEmpty ||
                                          validInvoiceDate(value)
                                      ? null
                                      : '请输入有效日期，例如 2026-10-29',
                                ),
                              ),
                            ],
                          ),
                          for (final a in [...r['attachments']])
                            ListTile(
                              title: Text(a['name']),
                              subtitle: invoiceRecognition(r, a),
                              trailing: IconButton(
                                tooltip: '移除附件',
                                onPressed:
                                    busy || pending != null || uploading > 0
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
                                    (rows.length >= 50 &&
                                        (r['attachments'].isNotEmpty ||
                                            r['failed'].isNotEmpty))
                                ? null
                                : () => upload(r),
                            icon: const Icon(Icons.attach_file),
                            label: Text(
                              uploading > 0 ? '上传中…' : '添加发票（图片 / PDF）',
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                TextButton.icon(
                  onPressed:
                      busy ||
                          pending != null ||
                          uploading > 0 ||
                          rows.length >= 50
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
        ),
      ),
    );
  }
}

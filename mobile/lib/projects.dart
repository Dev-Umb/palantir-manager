import 'dart:async';

import 'package:flutter/material.dart';

import 'api.dart';
import 'list_filters.dart';
import 'relation_field.dart';
import 'customer_fields.dart';
import 'ui.dart';
import 'attachments.dart';
import 'assistant.dart';

class ProjectsPage extends StatefulWidget {
  final PalantirApi api;
  const ProjectsPage({super.key, required this.api});
  @override
  State<ProjectsPage> createState() => _ProjectsPageState();
}

class _ProjectsPageState extends State<ProjectsPage> {
  final search = TextEditingController();
  Timer? timer;
  List<Map<String, dynamic>> records = [], fields = [];
  Map<String, dynamic> relationOptions = {};
  ListFilters filters = const ListFilters();
  int? total;
  bool retryMore = false;
  String? error;
  bool busy = true;
  int page = 1, lastPage = 1, generation = 0;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    timer?.cancel();
    search.dispose();
    super.dispose();
  }

  Future<void> load({bool more = false}) async {
    final ticket = ++generation;
    setState(() {
      busy = true;
      error = null;
      retryMore = more;
      if (!more) {
        records = [];
        page = 1;
        lastPage = 1;
        total = null;
      }
    });
    try {
      final data = await widget.api.page(
        filters.path('project', search.text, more ? page + 1 : 1),
      );
      if (!mounted || ticket != generation) return;
      final pagination = mapOf(data['records']);
      setState(() {
        records = more
            ? [...records, ...maps(pagination['data'])]
            : maps(pagination['data']);
        page = pagination['current_page'] ?? 1;
        lastPage = pagination['last_page'] ?? 1;
        total = pagination['total'];
        fields = maps(mapOf(data['currentObject'])['fields']);
        relationOptions = mapOf(data['relationOptions']);
      });
    } catch (e) {
      if (mounted && ticket == generation) setState(() => error = '$e');
    } finally {
      if (mounted && ticket == generation) setState(() => busy = false);
    }
  }

  Future<void> detail(Map<String, dynamic> r) async {
    await push(context, ProjectDetail(api: widget.api, id: r['id'].toString()));
    if (mounted) load();
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 8, 10),
        child: Row(
          children: [
            const Expanded(
              child: Text(
                '我的项目',
                style: TextStyle(fontSize: 24, fontWeight: FontWeight.w600),
              ),
            ),
            if (widget.api.can('object.project.create'))
              IconButton(
                tooltip: '新建项目',
                onPressed: () async {
                  await push(context, ProjectEditor(api: widget.api));
                  if (mounted) load();
                },
                icon: const Icon(Icons.add),
              ),
          ],
        ),
      ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20),
        child: TextField(
          controller: search,
          onChanged: (_) {
            timer?.cancel();
            generation++;
            setState(() {
              busy = true;
              error = null;
            });
            timer = Timer(const Duration(milliseconds: 350), () => load());
          },
          decoration: InputDecoration(
            hintText: '搜索项目名称、编号、客户',
            prefixIcon: const Icon(Icons.search),
            suffixIcon: IconButton(
              tooltip: '清除搜索',
              onPressed: () {
                timer?.cancel();
                search.clear();
                load();
              },
              icon: const Icon(Icons.close),
            ),
          ),
        ),
      ),
      ListFilterBar(
        api: widget.api,
        fields: fields,
        relationOptions: relationOptions,
        value: filters,
        onChanged: (value) {
          timer?.cancel();
          filters = value;
          load();
        },
      ),
      Expanded(
        child: RefreshIndicator(
          onRefresh: load,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(20),
            children: [
              if (error != null) ...[
                errorBox(error!),
                OutlinedButton(
                  onPressed: busy ? null : () => load(more: retryMore),
                  child: const Text('重试'),
                ),
              ],
              if (!busy && error == null && total != null) Text('共 $total 个项目'),
              if (busy) const LinearProgressIndicator(),
              if (!busy && error == null && records.isEmpty)
                note('没有找到相关项目，请调整关键词或筛选条件。'),
              ...records.map(
                (r) => ProjectCard(record: r, onTap: () => detail(r)),
              ),
              if (page < lastPage)
                OutlinedButton(
                  onPressed: busy ? null : () => load(more: true),
                  child: const Text('加载更多'),
                ),
            ],
          ),
        ),
      ),
    ],
  );
}

class ProjectDetail extends StatefulWidget {
  final PalantirApi api;
  final String id;
  const ProjectDetail({super.key, required this.api, required this.id});
  @override
  State<ProjectDetail> createState() => _ProjectDetailState();
}

class _ProjectDetailState extends State<ProjectDetail> {
  int revision = 0, tab = 0;
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('项目详情'),
      actions: [
        if (widget.api.can('ai.harness.view'))
          IconButton(
            tooltip: 'AI 助手',
            onPressed: () => openFlow(context, AssistantPage(api: widget.api)),
            icon: const Icon(Icons.auto_awesome_outlined),
          ),
      ],
    ),
    body: AsyncPage(
      key: ValueKey(revision),
      load: () => widget.api.recordPage('project', widget.id),
      builder: (data) {
        final r = mapOf(data['selectedRecord']),
            p = mapOf(r['payload']),
            display = mapOf(r['display']),
            fields = maps(mapOf(data['currentObject'])['fields']);
        final editable =
            r['can_update'] == true && mapOf(data['can'])['update'] == true;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Align(
              alignment: Alignment.centerLeft,
              child: tag(textOf(r['code'])),
            ),
            const SizedBox(height: 12),
            Text(
              textOf(p['name'] ?? r['title']),
              style: const TextStyle(fontSize: 25, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Text(
              textOf(display['customer_id']),
              style: const TextStyle(color: Colors.blueGrey),
            ),
            if (!editable) note('此项目仅可查看，当前没有编辑权限。'),
            const SizedBox(height: 18),
            SegmentedButton<int>(
              segments: const [
                ButtonSegment(value: 0, label: Text('概况')),
                ButtonSegment(value: 1, label: Text('合同')),
                ButtonSegment(value: 2, label: Text('回款')),
              ],
              selected: {tab},
              onSelectionChanged: (v) => setState(() => tab = v.first),
            ),
            const SizedBox(height: 18),
            if (tab == 0) ...[
              panel(
                Column(
                  children: [
                    kv('总体状态', p['overall_status']),
                    kv('合同状态', p['contract_status']),
                    kv('负责业务员', display['business_owner_user_id']),
                    kv('项目对接日期', p['handover_date']),
                  ],
                ),
              ),
              panel(
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      '客户与联系人',
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        fontSize: 17,
                      ),
                    ),
                    kv('客户', display['customer_id']),
                    kv('联系人', display['customer_contact_ids']),
                    kv('客户地址', display['customer_address']),
                    TextButton(
                      onPressed: () => openFlow(
                        context,
                        CustomerDetail(
                          api: widget.api,
                          id: p['customer_id'].toString(),
                        ),
                      ),
                      child: const Text('查看客户与电话 →'),
                    ),
                  ],
                ),
              ),
              const Text(
                '当前风险点',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
              ),
              note(textOf(p['risk'])),
              const Text(
                '跟进备注',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
              ),
              note(textOf(p['remark'])),
              ExpansionTile(
                title: const Text('更多项目资料'),
                children: fields
                    .where(
                      (f) => !['name', 'risk', 'remark'].contains(f['key']),
                    )
                    .map(
                      (f) => kv(f['label'], display[f['key']] ?? p[f['key']]),
                    )
                    .toList(),
              ),
            ],
            if (tab == 1) ...[
              if (maps(r['contracts']).isEmpty) note('当前项目暂无合同。'),
              ...maps(r['contracts'])
                  .map((c) => ContractCard(api: widget.api, contract: c)),
              if (widget.api.canUpload)
                OutlinedButton.icon(
                  onPressed: () =>
                      openFlow(context, ContractIntakePage(api: widget.api)),
                  icon: const Icon(Icons.upload_file),
                  label: const Text('上传并识别合同'),
                ),
            ],
            if (tab == 2) ...[
              panel(
                Column(
                  children: [
                    for (final pair in const [
                      ['contract_amount', '合同金额'],
                      ['occurred_amount', '已发生金额'],
                      ['paid_amount', '已回款金额'],
                      ['unpaid_amount', '未回款金额'],
                      ['reconciled_amount', '对账金额'],
                      ['invoiced_amount', '开票金额'],
                      ['uninvoiced_amount', '未开票金额'],
                    ])
                      kv(pair[1], amount(p[pair[0]])),
                    kv('回款进度', progress(p)),
                    kv('末次回款日期', p['last_payment_date']),
                    kv('催款次数', p['collection_count']),
                  ],
                ),
              ),
              note('未回款金额使用项目主档值。回款进度按已回款 ÷ 已发生，金额缺失不按零处理。'),
            ],
            if (editable)
              Padding(
                padding: const EdgeInsets.only(top: 18),
                child: FilledButton.icon(
                  onPressed: () async {
                    await push(
                      context,
                      ProjectEditor(api: widget.api, id: widget.id),
                    );
                    if (mounted) setState(() => revision++);
                  },
                  icon: const Icon(Icons.edit_outlined),
                  label: const Text('编辑项目'),
                ),
              ),
          ],
        );
      },
    ),
  );
}

const attachmentLabels = {
  'processing_letter_attachments': '加工函附件',
  'contract_attachments': '合同附件',
  'statement_attachments': '对账单附件',
};

Map<String, dynamic> contractWritePayload(Map<String, dynamic> contract) {
  const fields = [
    'id',
    'status',
    'ctype',
    'amount',
    'signed_date',
    'contract_chase_record',
    'contract_qty',
    'remark',
  ];
  final result = <String, dynamic>{
    for (final key in fields)
      if (contract.containsKey(key)) key: contract[key],
  };
  final removed = mapOf(contract['removed_attachments']);
  if (removed.values.any((items) => items is List && items.isNotEmpty)) {
    result['removed_attachments'] = removed;
  }
  for (final key in attachmentLabels.keys) {
    final uploads = (contract['new_$key'] as List? ?? []).cast<UploadFile>();
    if (uploads.isNotEmpty) result[key] = uploads;
  }
  return result;
}

class ContractCard extends StatelessWidget {
  final PalantirApi api;
  final Map<String, dynamic> contract;
  const ContractCard({super.key, required this.api, required this.contract});
  @override
  Widget build(BuildContext context) {
    final p = mapOf(contract['payload']);
    return panel(
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  textOf(contract['code']),
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
              ),
              tag(textOf(p['status'])),
            ],
          ),
          kv('合同类型', p['ctype']),
          kv('本份合同金额', amount(p['amount'])),
          kv('签订日期', p['signed_date']),
          kv('合同数量', p['contract_qty']),
          kv('催要记录', p['contract_chase_record']),
          kv('备注', p['remark']),
          for (final entry in attachmentLabels.entries) ...[
            const Divider(),
            Text(
              entry.value,
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
            if ((p[entry.key] as List? ?? []).isEmpty)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Text('暂无附件', style: TextStyle(color: Colors.blueGrey)),
              ),
            for (var i = 0; i < (p[entry.key] as List? ?? []).length; i++)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.description_outlined),
                title: Text('${entry.value} ${i + 1}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => push(
                  context,
                  AttachmentPage(
                    api: api,
                    path: p[entry.key][i].toString(),
                    name: '${entry.value} ${i + 1}',
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class CustomersPage extends StatelessWidget {
  final PalantirApi api;
  const CustomersPage({super.key, required this.api});
  @override
  Widget build(BuildContext context) => Column(
    children: [
      const Padding(
        padding: EdgeInsets.all(20),
        child: Align(
          alignment: Alignment.centerLeft,
          child: Text(
            '客户',
            style: TextStyle(fontSize: 24, fontWeight: FontWeight.w600),
          ),
        ),
      ),
      Expanded(child: CustomerList(api: api)),
    ],
  );
}

class CustomerList extends StatefulWidget {
  final PalantirApi api;
  const CustomerList({super.key, required this.api});
  @override
  State<CustomerList> createState() => _CustomerListState();
}

class _CustomerListState extends State<CustomerList> {
  final search = TextEditingController();
  Timer? timer;
  List<Map<String, dynamic>> records = [], fields = [];
  Map<String, dynamic> relationOptions = {};
  ListFilters filters = const ListFilters();
  int page = 1, lastPage = 1, generation = 0;
  int? total;
  bool busy = true, retryMore = false;
  String? error;

  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    timer?.cancel();
    search.dispose();
    super.dispose();
  }

  Future<void> load({bool more = false}) async {
    final ticket = ++generation;
    setState(() {
      busy = true;
      error = null;
      retryMore = more;
      if (!more) {
        records = [];
        page = 1;
        lastPage = 1;
        total = null;
      }
    });
    try {
      final data = await widget.api.page(
        filters.path('customer', search.text, more ? page + 1 : 1),
      );
      if (!mounted || ticket != generation) return;
      final pagination = mapOf(data['records']);
      setState(() {
        records = more
            ? [...records, ...maps(pagination['data'])]
            : maps(pagination['data']);
        page = pagination['current_page'] ?? 1;
        lastPage = pagination['last_page'] ?? 1;
        total = pagination['total'];
        fields = maps(mapOf(data['currentObject'])['fields']);
        relationOptions = mapOf(data['relationOptions']);
      });
    } catch (e) {
      if (mounted && ticket == generation) setState(() => error = '$e');
    } finally {
      if (mounted && ticket == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20),
        child: TextField(
          controller: search,
          decoration: InputDecoration(
            hintText: '搜索客户名称',
            prefixIcon: const Icon(Icons.search),
            suffixIcon: IconButton(
              tooltip: '清除搜索',
              icon: const Icon(Icons.close),
              onPressed: () {
                timer?.cancel();
                search.clear();
                load();
              },
            ),
          ),
          onChanged: (_) {
            timer?.cancel();
            generation++;
            setState(() {
              busy = true;
              error = null;
            });
            timer = Timer(const Duration(milliseconds: 350), () => load());
          },
          onSubmitted: (_) {
            timer?.cancel();
            load();
          },
        ),
      ),
      ListFilterBar(
        api: widget.api,
        fields: fields,
        relationOptions: relationOptions,
        value: filters,
        onChanged: (value) {
          timer?.cancel();
          filters = value;
          load();
        },
      ),
      Expanded(
        child: RefreshIndicator(
          onRefresh: load,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(20),
            children: [
              if (busy) const LinearProgressIndicator(),
              if (error != null) ...[
                errorBox(error!),
                OutlinedButton(
                  onPressed: busy ? null : () => load(more: retryMore),
                  child: const Text('重试'),
                ),
              ],
              if (!busy && error == null && total != null) Text('共 $total 个客户'),
              if (!busy && error == null && records.isEmpty)
                note('没有找到相关客户，请调整关键词或筛选条件。'),
              ...records.map(
                (c) => Card(
                  child: ListTile(
                    leading: const CircleAvatar(
                      child: Icon(Icons.business_outlined),
                    ),
                    title: Text(textOf(c['title'])),
                    subtitle: Text(textOf(mapOf(c['payload'])['address'])),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () async {
                      await push(
                        context,
                        CustomerDetail(api: widget.api, id: c['id'].toString()),
                      );
                      if (mounted) load();
                    },
                  ),
                ),
              ),
              if (page < lastPage)
                OutlinedButton(
                  onPressed: busy ? null : () => load(more: true),
                  child: const Text('加载更多'),
                ),
              note('同名客户结合地址核对，不自动合并。'),
            ],
          ),
        ),
      ),
    ],
  );
}

class CustomerDetail extends StatelessWidget {
  final PalantirApi api;
  final String id;
  const CustomerDetail({super.key, required this.api, required this.id});
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('客户详情')),
    body: AsyncPage(
      load: () => api.recordPage('customer', id),
      builder: (data) {
        final r = mapOf(data['selectedRecord']), p = mapOf(r['payload']);
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              textOf(r['title']),
              style: const TextStyle(fontSize: 25, fontWeight: FontWeight.w600),
            ),
            panel(
              Column(
                children: maps(mapOf(data['currentObject'])['fields'])
                    .map(
                      (f) => kv(
                        f['label'],
                        mapOf(r['display'])[f['key']] ?? p[f['key']],
                      ),
                    )
                    .toList(),
              ),
            ),
            const Text(
              '联系人',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
            ),
            ...maps(r['contacts']).map((c) {
              final cp = mapOf(c['payload']);
              final phone = textOf(cp['phone'] ?? c['phone']);
              return Card(
                child: ListTile(
                  title: Text(textOf(cp['name'] ?? c['name'] ?? c['title'])),
                  subtitle: Text(phone),
                  trailing: IconButton(
                    tooltip: '拨打电话',
                    icon: const Icon(Icons.phone_outlined),
                    onPressed: phone == '未填写'
                        ? null
                        : () => openExternal(
                            context,
                            Uri(scheme: 'tel', path: phone),
                          ),
                  ),
                ),
              );
            }),
            const Text(
              '关联项目',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
            ),
            ...maps(r['cooperation_projects']).map(
              (p) => Card(
                child: ListTile(
                  title: Text(textOf(p['name'] ?? p['title'])),
                  subtitle: Text(textOf(p['code'])),
                  onTap: () => openFlow(
                    context,
                    ProjectDetail(api: api, id: p['id'].toString()),
                  ),
                ),
              ),
            ),
          ],
        );
      },
    ),
  );
}

class ProjectEditor extends StatefulWidget {
  final PalantirApi api;
  final String? id;
  const ProjectEditor({super.key, required this.api, this.id});
  @override
  State<ProjectEditor> createState() => _ProjectEditorState();
}

class _ProjectEditorState extends State<ProjectEditor> {
  Map<String, dynamic>? data;
  Map<String, dynamic> payload = {};
  List<Map<String, dynamic>> contracts = [];
  List<String> deleted = [];
  Map<String, dynamic> customerProfile = {};
  bool customerChanged = false;
  List<Map<String, dynamic>>? customerConflicts;
  final controls = <String, TextEditingController>{};
  final formScroll = ScrollController();
  bool busy = false, dirty = false;
  String? error;
  int tab = 0;
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    for (final c in controls.values) {
      c.dispose();
    }
    formScroll.dispose();
    super.dispose();
  }

  Future<void> load() async {
    try {
      final d = await widget.api.recordPage(
        'project',
        widget.id,
        editing: true,
      );
      final r = mapOf(d['selectedRecord']);
      payload = Map.of(mapOf(r['payload']));
      contracts = maps(r['contracts'])
          .map(
            (c) => {
              'id': c['id'],
              'code': c['code'],
              ...mapOf(c['payload']),
              'attachment_tokens': mapOf(c['attachment_tokens']),
              'attachment_previews': mapOf(c['attachment_previews']),
            },
          )
          .toList();
      if (widget.id == null) {
        for (final f in maps(mapOf(d['currentObject'])['fields'])) {
          if (f['default'] != null) payload[f['key']] = f['default'];
        }
      }
      customerProfile = projectCustomerProfile(d);
      if (mounted) setState(() => data = d);
    } catch (e) {
      if (mounted) setState(() => error = '$e');
    }
  }

  Future<bool> mayLeave() async {
    if (!dirty) return true;
    return await showDialog<bool>(
          context: context,
          builder: (c) => AlertDialog(
            title: const Text('还有未保存的修改'),
            content: const Text('返回会放弃本次修改。'),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(c, false),
                child: const Text('继续编辑'),
              ),
              TextButton(
                onPressed: () => Navigator.pop(c, true),
                child: const Text('放弃修改'),
              ),
            ],
          ),
        ) ??
        false;
  }

  Future<void> save({bool confirmCustomer = false}) async {
    FocusScope.of(context).unfocus();
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final writtenPayload = {...payload};
      if (customerChanged && mapOf(data?['can'])['manage_customers'] == true) {
        final profile = customerProfilePayload(
          customerProfile,
          confirmed: confirmCustomer,
        );
        if (!confirmCustomer) {
          final preview = await widget.api.send(
            '/project-customer-profile/preview',
            profile,
          );
          final conflicts = maps(preview['conflicts']);
          if (conflicts.isNotEmpty) {
            if (mounted) {
              setState(() {
                customerConflicts = conflicts;
                tab = 1;
              });
              WidgetsBinding.instance.addPostFrameCallback((_) {
                if (mounted && formScroll.hasClients) formScroll.jumpTo(0);
              });
            }
            return;
          }
        }
        writtenPayload['customer_profile'] = profile;
      }
      final body = <String, dynamic>{'payload': writtenPayload};
      if (mapOf(data?['can'])['manage_contracts'] == true) {
        body['contracts'] = contracts.map(contractWritePayload).toList();
        body['deleted_contract_ids'] = deleted;
      }
      bool hasFile = contracts.any(
        (c) => attachmentLabels.keys.any(
          (f) => (c['new_$f'] as List? ?? []).isNotEmpty,
        ),
      );
      if (hasFile) {
        await widget.api.multipart(
          widget.id == null ? '/objects/project' : '/records/${widget.id}',
          body,
          method: widget.id == null ? 'POST' : 'PUT',
        );
      } else {
        await widget.api.send(
          widget.id == null ? '/objects/project' : '/records/${widget.id}',
          body,
          method: widget.id == null ? 'POST' : 'PUT',
        );
      }
      if (mounted) {
        dirty = false;
        toast(context, '项目已保存');
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) {
        setState(() => error = '$e');
        toast(context, '$e');
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !dirty && !busy,
    onPopInvokedWithResult: (didPop, result) async {
      if (didPop || busy) return;
      if (await mayLeave() && context.mounted) {
        setState(() => dirty = false);
        if (context.mounted) Navigator.pop(context);
      }
    },
    child: Scaffold(
      appBar: AppBar(
        title: Text(widget.id == null ? '新建项目' : '编辑项目'),
        bottom: busy && data != null
            ? const PreferredSize(
                preferredSize: Size.fromHeight(3),
                child: LinearProgressIndicator(),
              )
            : null,
      ),
      body: data == null
          ? error == null
                ? const EditorLoading()
                : ListView(
                    padding: const EdgeInsets.all(20),
                    children: [
                      errorBox(error!),
                      FilledButton.icon(
                        onPressed: () {
                          setState(() => error = null);
                          load();
                        },
                        icon: const Icon(Icons.refresh),
                        label: const Text('重新加载'),
                      ),
                    ],
                  )
          : Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: steel.withValues(alpha: .1),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: const Icon(Icons.edit_note, color: steel),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              widget.id == null
                                  ? '创建业务项目'
                                  : textOf(
                                      mapOf(
                                        mapOf(
                                          data!['selectedRecord'],
                                        )['payload'],
                                      )['name'],
                                    ),
                              style: const TextStyle(
                                fontWeight: FontWeight.w600,
                                fontSize: 17,
                                color: ink,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            Text(
                              busy
                                  ? '正在保存，请稍候'
                                  : dirty
                                  ? '有未保存的修改'
                                  : '按分组维护项目资料',
                              style: const TextStyle(
                                fontSize: 12,
                                color: Colors.blueGrey,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  child: SegmentedButton<int>(
                    segments: const [
                      ButtonSegment(value: 0, label: Text('项目资料')),
                      ButtonSegment(value: 1, label: Text('客户联系人')),
                      ButtonSegment(value: 2, label: Text('合同')),
                    ],
                    selected: {tab},
                    onSelectionChanged: busy
                        ? null
                        : (v) => setState(() => tab = v.first),
                  ),
                ),
                Expanded(
                  child: AbsorbPointer(
                    absorbing: busy,
                    child: ListView(
                      controller: formScroll,
                      padding: const EdgeInsets.all(20),
                      children: [
                        if (error != null) errorBox(error!),
                        if (tab == 1 && customerConflicts != null)
                          panel(
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  '客户资料存在冲突',
                                  style: TextStyle(fontWeight: FontWeight.w600),
                                ),
                                note('确认后将覆盖共享客户资料，并影响关联该客户的其他项目。'),
                                for (final c in customerConflicts!)
                                  kv(
                                    textOf(c['label']),
                                    '${c['current'] ?? ''} → ${c['submitted'] ?? ''}',
                                  ),
                                FilledButton(
                                  onPressed: busy
                                      ? null
                                      : () => save(confirmCustomer: true),
                                  child: const Text('确认覆盖并保存'),
                                ),
                                TextButton(
                                  onPressed: busy
                                      ? null
                                      : () => setState(
                                          () => customerConflicts = null,
                                        ),
                                  child: const Text('取消，继续编辑'),
                                ),
                              ],
                            ),
                          ),
                        if (tab == 1 &&
                            mapOf(data!['can'])['manage_customers'] == true)
                          ProjectCustomerFields(
                            api: widget.api,
                            profile: customerProfile,
                            options: mapOf(data!['relationOptions']),
                            projectId: widget.id,
                            enabled: !busy,
                            onChanged: (profile) => setState(() {
                              customerProfile = profile;
                              customerChanged = true;
                              customerConflicts = null;
                              payload['customer_id'] = profile['customer_id'];
                              payload['customer_contact_ids'] =
                                  maps(profile['contacts'])
                                      .where((c) => c['id'] != null)
                                      .map((c) => c['id'])
                                      .toList();
                              markDirty();
                            }),
                          ),
                        if (tab != 2)
                          panel(
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                ...maps(mapOf(data!['currentObject'])['fields'])
                                    .where((f) {
                                      final key = f['key'].toString();
                                      final customer = key.startsWith(
                                        'customer',
                                      );
                                      if (tab == 1 &&
                                          mapOf(
                                                data!['can'],
                                              )['manage_customers'] ==
                                              true &&
                                          [
                                            'customer_id',
                                            'customer_contact_ids',
                                            'customer_address',
                                            'customer_level',
                                            'customer_nature',
                                          ].contains(key)) {
                                        return false;
                                      }
                                      return tab == 1
                                          ? customer
                                          : !customer && !isReadOnly(f);
                                    })
                                    .map(field),
                              ],
                            ),
                          ),
                        if (tab == 0)
                          ExpansionTile(
                            title: const Text('只读参考信息'),
                            children:
                                maps(mapOf(data!['currentObject'])['fields'])
                                    .where(
                                      (f) =>
                                          !f['key'].toString().startsWith(
                                            'customer',
                                          ) &&
                                          isReadOnly(f),
                                    )
                                    .map(field)
                                    .toList(),
                          ),
                        if (tab == 2) ...contractFields(),
                      ],
                    ),
                  ),
                ),
                SafeArea(
                  top: false,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        onPressed: busy ? null : save,
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            if (busy)
                              const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            else
                              const Icon(Icons.check, size: 20),
                            const SizedBox(width: 8),
                            Text(busy ? '正在保存…' : '保存修改'),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
    ),
  );
  void markDirty() {
    if (!dirty) setState(() => dirty = true);
  }

  bool isReadOnly(Map<String, dynamic> f) =>
      f['readonly'] == true ||
      ['readonly', 'lookup', 'code'].contains(f['type']) ||
      f['system'] == 'code';
  Widget field(Map<String, dynamic> f) {
    final key = f['key'].toString(), type = f['type'];
    final value = payload[key];
    final readonly =
        f['readonly'] == true ||
        ['readonly', 'lookup', 'code'].contains(type) ||
        f['system'] == 'code';
    if (readonly) {
      return kv(
        f['label'],
        mapOf(mapOf(data?['selectedRecord'])['display'])[key] ?? value,
      );
    }
    final label = '${f['label']}${f['required'] == true ? ' *' : ''}';
    if ([
      'relation',
      'multirelation',
      'account',
      'multiaccount',
      'creatable_relation',
    ].contains(type)) {
      final multiple = ['multirelation', 'multiaccount'].contains(type);
      final options = Map<String, dynamic>.of(
        mapOf(mapOf(data!['relationOptions'])[key]),
      );
      if (key == 'customer_contact_ids') {
        options['search_url'] =
            '/relation-options?source_object=project&field=customer_contact_ids&context[customer_id]=${payload['customer_id']}${widget.id == null ? '' : '&editing_record=${widget.id}'}';
      }
      return InlineRelationField(
        key: ValueKey(
          '$key-${key == 'customer_contact_ids' ? payload['customer_id'] : ''}',
        ),
        api: widget.api,
        label: label,
        options: options,
        multiple: multiple,
        enabled:
            !busy &&
            (key != 'customer_contact_ids' || payload['customer_id'] != null),
        selected: value is List
            ? value.map((v) => '$v').toList()
            : value == null
            ? []
            : ['$value'],
        onChanged: (items) => setState(() {
          final ids = items.map((i) => '${i['id']}').toList();
          if (key == 'customer_id' && value != ids.firstOrNull) {
            payload['customer_contact_ids'] = [];
          }
          payload[key] = multiple ? ids : ids.firstOrNull;
          data!['relationOptions'] = {
            ...mapOf(data!['relationOptions']),
            key: {...options, 'selectedItems': items},
          };
          markDirty();
        }),
      );
    }
    if (type == 'select') {
      final options = (f['options'] as List? ?? [])
          .map(
            (o) =>
                o is Map ? (o['value'] ?? o['label']).toString() : o.toString(),
          )
          .toList();
      return Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: DropdownButtonFormField<String>(
          initialValue: options.contains(value?.toString())
              ? value.toString()
              : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: label),
          items: options
              .map((v) => DropdownMenuItem(value: v, child: Text(v)))
              .toList(),
          onChanged: busy
              ? null
              : (v) {
                  payload[key] = v;
                  markDirty();
                },
        ),
      );
    }
    final controller = controls.putIfAbsent(
      key,
      () => TextEditingController(text: value?.toString() ?? ''),
    );
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: TextField(
        controller: controller,
        enabled: !busy,
        minLines: key == 'remark' ? 3 : 1,
        maxLines: key == 'remark' ? 5 : 1,
        keyboardType: type == 'number'
            ? const TextInputType.numberWithOptions(decimal: true, signed: true)
            : type == 'date'
            ? TextInputType.datetime
            : TextInputType.text,
        decoration: InputDecoration(
          labelText: label,
          hintText: type == 'date' ? 'YYYY-MM-DD' : null,
        ),
        onChanged: (v) {
          payload[key] = type == 'number'
              ? (v.isEmpty ? null : num.tryParse(v) ?? v)
              : v;
          markDirty();
        },
      ),
    );
  }

  List<Widget> contractFields() {
    if (mapOf(data!['can'])['manage_contracts'] != true) {
      return [note('当前账号不能维护合同。')];
    }
    return [
      note('合同随项目一起保存；长按或点击移除可删除单个附件，保存前可撤销。'),
      for (var i = 0; i < contracts.length; i++) contractEditor(i),
      OutlinedButton.icon(
        onPressed: () {
          setState(() {
            contracts.add({
              'status': '未签署',
              'ctype': '',
              'amount': '',
              'signed_date': '',
              'contract_qty': '',
              'remark': '',
            });
            markDirty();
          });
        },
        icon: const Icon(Icons.add),
        label: const Text('添加合同'),
      ),
    ];
  }

  void removePending(Map<String, dynamic> contract, String field, int index) {
    setState(() {
      (contract['new_$field'] as List).removeAt(index);
      markDirty();
    });
  }

  Future<void> toggleSaved(
    Map<String, dynamic> contract,
    String field,
    String token,
  ) async {
    final removed = mapOf(contract['removed_attachments']);
    final selected = List<String>.from(removed[field] as List? ?? []);
    final undo = selected.contains(token);
    if (!undo) {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('移除此附件？'),
          content: const Text('保存项目后生效，其他附件和合同保持不变。保存前可以撤销。'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('取消'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('确认移除'),
            ),
          ],
        ),
      );
      if (confirmed != true || !mounted || busy) return;
    }
    setState(() {
      if (undo) {
        selected.remove(token);
      } else {
        selected.add(token);
      }
      contract['removed_attachments'] = {...removed, field: selected};
      markDirty();
    });
  }

  Widget savedAttachment(
    Map<String, dynamic> contract,
    String field,
    String label,
    int index,
  ) {
    final path = contract[field][index].toString();
    final tokens = mapOf(contract['attachment_tokens'])[field] as List? ?? [];
    final token = index < tokens.length ? tokens[index].toString() : null;
    final removed =
        (mapOf(contract['removed_attachments'])[field] as List? ?? []).contains(
          token,
        );
    final previews = maps(mapOf(contract['attachment_previews'])[field]);
    final name = index < previews.length
        ? textOf(previews[index]['name'])
        : '$label ${index + 1}';
    return ListTile(
      leading: Icon(
        removed ? Icons.delete_outline : Icons.description_outlined,
        color: removed ? Colors.red : steel,
      ),
      title: Text(
        name,
        style: removed
            ? const TextStyle(decoration: TextDecoration.lineThrough)
            : null,
      ),
      subtitle: Text(
        removed
            ? '待移除 · 保存后生效'
            : token == null
            ? '当前服务暂不支持单附件移除'
            : '已保存 · 长按可移除',
      ),
      onTap: () => push(
        context,
        AttachmentPage(api: widget.api, path: path, name: name),
      ),
      onLongPress: busy || token == null
          ? null
          : () => toggleSaved(contract, field, token),
      trailing: token == null
          ? null
          : IconButton(
              tooltip: removed ? '撤销移除' : '移除附件',
              onPressed: busy
                  ? null
                  : () => toggleSaved(contract, field, token),
              icon: Icon(removed ? Icons.undo : Icons.delete_outline),
            ),
    );
  }

  Widget contractEditor(int i) {
    final c = contracts[i];
    return panel(
      ExpansionTile(
        key: ObjectKey(c),
        initiallyExpanded: i == 0,
        tilePadding: EdgeInsets.zero,
        title: Text(textOf(c['code'] ?? '新合同 ${i + 1}')),
        children: [
          DropdownButtonFormField<String>(
            initialValue: ['未签署', '已有加工函', '已签署'].contains(c['status'])
                ? c['status']
                : '未签署',
            decoration: const InputDecoration(labelText: '合同状态'),
            items: [
              '未签署',
              '已有加工函',
              '已签署',
            ].map((v) => DropdownMenuItem(value: v, child: Text(v))).toList(),
            onChanged: (v) {
              c['status'] = v;
              markDirty();
            },
          ),
          const SizedBox(height: 14),
          DropdownButtonFormField<String>(
            initialValue: ['销售合同', '加工合同', '补充协议'].contains(c['ctype'])
                ? c['ctype']
                : null,
            decoration: const InputDecoration(labelText: '合同类型'),
            items: [
              '销售合同',
              '加工合同',
              '补充协议',
            ].map((v) => DropdownMenuItem(value: v, child: Text(v))).toList(),
            onChanged: (v) {
              c['ctype'] = v;
              markDirty();
            },
          ),
          for (final pair in const [
            ['amount', '本份合同金额（元）'],
            ['signed_date', '签订日期'],
            ['contract_qty', '合同数量'],
            ['contract_chase_record', '合同催要记录'],
            ['remark', '合同备注'],
          ])
            Padding(
              padding: const EdgeInsets.only(top: 14),
              child: TextFormField(
                initialValue: c[pair[0]]?.toString() ?? '',
                decoration: InputDecoration(labelText: pair[1]),
                onChanged: (v) {
                  c[pair[0]] = v;
                  markDirty();
                },
              ),
            ),
          for (final entry in attachmentLabels.entries) ...[
            const SizedBox(height: 16),
            Align(alignment: Alignment.centerLeft, child: Text(entry.value)),
            for (
              var fileIndex = 0;
              fileIndex < (c[entry.key] as List? ?? []).length;
              fileIndex++
            )
              savedAttachment(c, entry.key, entry.value, fileIndex),
            for (
              var fileIndex = 0;
              fileIndex < (c['new_${entry.key}'] as List? ?? []).length;
              fileIndex++
            )
              ListTile(
                leading: const Icon(Icons.upload_file_outlined, color: steel),
                title: Text(
                  (c['new_${entry.key}'][fileIndex] as UploadFile).name,
                ),
                subtitle: const Text('待保存 · 长按可移除'),
                onLongPress: busy
                    ? null
                    : () => removePending(c, entry.key, fileIndex),
                trailing: IconButton(
                  tooltip: '移除待上传附件',
                  onPressed: busy
                      ? null
                      : () => removePending(c, entry.key, fileIndex),
                  icon: const Icon(Icons.close),
                ),
              ),
            TextButton.icon(
              onPressed: () async {
                try {
                  final file = await selectUpload();
                  if (file != null && mounted) {
                    setState(() {
                      (c['new_${entry.key}'] ??= []).add(file);
                      markDirty();
                    });
                  }
                } catch (e) {
                  if (mounted) toast(context, '$e');
                }
              },
              icon: const Icon(Icons.attach_file),
              label: const Text('追加附件'),
            ),
          ],
          TextButton(
            onPressed: () async {
              final confirm = await showDialog<bool>(
                context: context,
                builder: (ctx) => AlertDialog(
                  title: const Text('删除这份合同？'),
                  content: const Text('保存项目后同步删除合同记录。'),
                  actions: [
                    TextButton(
                      onPressed: () => Navigator.pop(ctx, false),
                      child: const Text('取消'),
                    ),
                    TextButton(
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('确认删除'),
                    ),
                  ],
                ),
              );
              if (confirm == true) {
                setState(() {
                  if (c['id'] != null) deleted.add(c['id'].toString());
                  contracts.removeAt(i);
                  markDirty();
                });
              }
            },
            child: const Text('删除合同'),
          ),
        ],
      ),
    );
  }
}

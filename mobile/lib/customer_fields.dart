import 'package:flutter/material.dart';

import 'api.dart';
import 'ui.dart';
import 'relation_field.dart';

Map<String, dynamic> projectCustomerProfile(Map<String, dynamic> data) {
  final record = mapOf(data['selectedRecord']),
      payload = mapOf(mapOf(data['selectedRecord'])['payload']);
  final options = mapOf(data['relationOptions']);
  final choices = [
    ...maps(mapOf(options['customer_id'])['items']),
    ...maps(mapOf(options['customer_id'])['selectedItems']),
  ];
  final option =
      choices.where((i) => i['id'] == payload['customer_id']).firstOrNull ?? {};
  final customer = mapOf(record['customer']);
  final meta = customer.isNotEmpty ? customer : mapOf(option['meta']);
  final contacts = maps(record['contacts']);
  final contactOptions = [
    ...maps(mapOf(options['customer_contact_ids'])['items']),
    ...maps(mapOf(options['customer_contact_ids'])['selectedItems']),
  ];
  return {
    'customer_id': customer['id'] ?? payload['customer_id'],
    'name': meta['name'] ?? option['title'] ?? option['label'] ?? '',
    'address': meta['address'] ?? '',
    'level': meta['level'] ?? '',
    'customer_nature': meta['customer_nature'] ?? '',
    'can_update': meta['can_update'] ?? true,
    'contacts': contacts.isNotEmpty
        ? contacts.map((c) => {...c}).toList()
        : (payload['customer_contact_ids'] as List? ?? []).map((id) {
            final item =
                contactOptions.where((i) => i['id'] == id).firstOrNull ?? {};
            return {
              'id': id,
              'name': mapOf(item['meta'])['name'] ?? item['label'] ?? '',
              'phone': mapOf(item['meta'])['phone'] ?? '',
            };
          }).toList(),
  };
}

Map<String, dynamic> customerProfilePayload(
  Map<String, dynamic> profile, {
  bool confirmed = false,
}) => {
  'customer_id': profile['customer_id'],
  for (final key in ['name', 'address', 'level', 'customer_nature'])
    key: '${profile[key] ?? ''}'.trim(),
  'overwrite_confirmed': confirmed,
  'contacts': maps(profile['contacts'])
      .map(
        (c) => {
          'id': c['id'],
          'name': '${c['name'] ?? ''}'.trim(),
          'phone': '${c['phone'] ?? ''}'.trim(),
        },
      )
      .toList(),
};

class ProjectCustomerFields extends StatefulWidget {
  final PalantirApi api;
  final Map<String, dynamic> profile, options;
  final String? projectId;
  final bool enabled;
  final ValueChanged<Map<String, dynamic>> onChanged;
  const ProjectCustomerFields({
    super.key,
    required this.api,
    required this.profile,
    required this.options,
    required this.onChanged,
    this.projectId,
    this.enabled = true,
  });
  @override
  State<ProjectCustomerFields> createState() => _ProjectCustomerFieldsState();
}

class _ProjectCustomerFieldsState extends State<ProjectCustomerFields> {
  int serial = 0;
  void change(Map<String, dynamic> next) => widget.onChanged(next);
  @override
  Widget build(BuildContext context) {
    final p = widget.profile;
    final readonly = p['can_update'] == false;
    final contacts = maps(p['contacts']);
    final customerId = p['customer_id'];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        InlineRelationField(
          api: widget.api,
          label: '选择已有客户',
          options: mapOf(widget.options['customer_id']),
          selected: customerId == null ? [] : ['$customerId'],
          enabled: widget.enabled,
          onChanged: (items) {
            final item = items.firstOrNull ?? {},
                meta = mapOf((items.firstOrNull ?? {})['meta']);
            change({
              'customer_id': item['id'],
              'name': meta['name'] ?? item['title'] ?? item['label'] ?? '',
              'address': meta['address'] ?? '',
              'level': meta['level'] ?? '',
              'customer_nature': meta['customer_nature'] ?? '',
              'can_update': meta['can_update'] ?? true,
              'contacts': <Map<String, dynamic>>[],
            });
          },
        ),
        if (readonly) note('该客户和联系人为只读，可选择已有联系人关联本项目。'),
        for (final f in const [
          ['name', '客户名称 *'],
          ['address', '客户地址'],
        ])
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: TextFormField(
              key: ValueKey('$customerId-${f[0]}'),
              initialValue: '${p[f[0]] ?? ''}',
              readOnly: readonly,
              enabled: widget.enabled,
              decoration: InputDecoration(labelText: f[1]),
              onChanged: (v) => change({...p, f[0]: v}),
            ),
          ),
        for (final f in const [
          ['level', '客户等级', 'A', 'B', 'C'],
          ['customer_nature', '客户性质', '国央企', '私企'],
        ])
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: DropdownButtonFormField<String>(
              key: ValueKey('$customerId-${f[0]}'),
              initialValue: f.skip(2).contains(p[f[0]]) ? p[f[0]] : '',
              isExpanded: true,
              decoration: InputDecoration(labelText: f[1]),
              items: ['', ...f.skip(2)]
                  .map(
                    (v) => DropdownMenuItem(
                      value: v,
                      child: Text(v.isEmpty ? '未选择' : v),
                    ),
                  )
                  .toList(),
              onChanged: readonly || !widget.enabled
                  ? null
                  : (v) => change({...p, f[0]: v}),
            ),
          ),
        if (customerId != null)
          InlineRelationField(
            key: ValueKey('contacts-$customerId'),
            api: widget.api,
            label: '选择已有联系人',
            options: {
              'items':
                  [
                        ...maps(
                          mapOf(
                            widget.options['customer_contact_ids'],
                          )['items'],
                        ),
                        ...maps(
                          mapOf(
                            widget.options['customer_contact_ids'],
                          )['selectedItems'],
                        ),
                      ]
                      .where(
                        (i) => mapOf(i['meta'])['customer_id'] == customerId,
                      )
                      .toList(),
              'selectedItems': contacts
                  .where((c) => c['id'] != null)
                  .map((c) => {'id': c['id'], 'label': c['name'], 'meta': c})
                  .toList(),
              'search_url':
                  '/relation-options?source_object=project&field=customer_contact_ids&context[customer_id]=$customerId${widget.projectId == null ? '' : '&editing_record=${widget.projectId}'}',
            },
            selected: contacts
                .where((c) => c['id'] != null)
                .map((c) => '${c['id']}')
                .toList(),
            multiple: true,
            enabled: widget.enabled,
            onChanged: (items) {
              final retained = contacts
                  .where(
                    (c) =>
                        c['id'] == null || items.any((i) => i['id'] == c['id']),
                  )
                  .toList();
              for (final item in items) {
                if (!retained.any((c) => c['id'] == item['id'])) {
                  retained.add({
                    'id': item['id'],
                    'name': mapOf(item['meta'])['name'] ?? item['label'],
                    'phone': mapOf(item['meta'])['phone'] ?? '',
                  });
                }
              }
              change({...p, 'contacts': retained});
            },
          ),
        const Text(
          '客户联系人',
          style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 8),
        note('联系人随项目一次保存。移除只取消本项目关联，不删除客户联系人主档。'),
        for (var i = 0; i < contacts.length; i++)
          Card(
            key: ValueKey(
              contacts[i]['id'] ?? contacts[i]['_key'] ?? 'initial-$i',
            ),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  for (final f in const [
                    ['name', '联系人姓名 *'],
                    ['phone', '手机号'],
                  ])
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: TextFormField(
                        initialValue: '${contacts[i][f[0]] ?? ''}',
                        readOnly:
                            readonly || contacts[i]['can_update'] == false,
                        enabled: widget.enabled,
                        decoration: InputDecoration(labelText: f[1]),
                        keyboardType: f[0] == 'phone'
                            ? TextInputType.phone
                            : TextInputType.text,
                        onChanged: (v) {
                          final rows = contacts.map((c) => {...c}).toList();
                          rows[i][f[0]] = v;
                          change({...p, 'contacts': rows});
                        },
                      ),
                    ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      onPressed: !widget.enabled
                          ? null
                          : () {
                              final rows = [...contacts]..removeAt(i);
                              change({...p, 'contacts': rows});
                            },
                      icon: const Icon(Icons.remove_circle_outline),
                      label: const Text('移除本项目关联'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        if (!readonly)
          TextButton.icon(
            onPressed: !widget.enabled || contacts.length >= 50
                ? null
                : () => change({
                    ...p,
                    'contacts': [
                      ...contacts,
                      {
                        'id': null,
                        '_key': 'new-${serial++}',
                        'name': '',
                        'phone': '',
                      },
                    ],
                  }),
            icon: const Icon(Icons.add),
            label: const Text('添加联系人'),
          ),
      ],
    );
  }
}

import 'package:flutter/material.dart';

import 'api.dart';
import 'relation_field.dart';

const filterLabels = {
  'equals': '等于',
  'not_equals': '不等于',
  'contains': '包含',
  'not_contains': '不包含',
  'greater_than': '大于',
  'greater_or_equal': '大于或等于',
  'less_than': '小于',
  'less_or_equal': '小于或等于',
  'before': '早于',
  'on_or_before': '早于或等于',
  'after': '晚于',
  'on_or_after': '晚于或等于',
  'between': '范围',
  'is_empty': '为空',
  'is_not_empty': '不为空',
};

List<Map<String, dynamic>> filterFields(List<Map<String, dynamic>> fields) =>
    fields
        .where(
          (f) =>
              f['scope'] != 'item' &&
              ![
                'file',
                'files',
                'multirelation',
                'multiaccount',
              ].contains(f['type']),
        )
        .toList();

List<String> filterOperators(Map<String, dynamic> field) {
  final type = field['type'];
  final List<String> operators;
  if (['number', 'range'].contains(type)) {
    operators = [
      'equals',
      'not_equals',
      'greater_than',
      'greater_or_equal',
      'less_than',
      'less_or_equal',
      'between',
    ];
  } else if (type == 'date') {
    operators = [
      'equals',
      'before',
      'on_or_before',
      'after',
      'on_or_after',
      'between',
    ];
  } else if ([
    'select',
    'relation',
    'creatable_relation',
    'account',
  ].contains(type)) {
    operators = ['equals', 'not_equals'];
  } else {
    operators = ['contains', 'not_contains', 'equals', 'not_equals'];
  }
  return [...operators, 'is_empty', 'is_not_empty'];
}

class RecordFilter {
  final String field, operator, value, label;
  const RecordFilter(
    this.field,
    this.operator, [
    this.value = '',
    this.label = '',
  ]);
  bool get needsValue => !['is_empty', 'is_not_empty'].contains(operator);
  RecordFilter withValue(String value, [String label = '']) =>
      RecordFilter(field, operator, value, label);
}

class ListFilters {
  final List<RecordFilter> rules;
  final String logic;
  const ListFilters({this.rules = const [], this.logic = 'and'});

  Map<String, String> get query => {
    if (rules.isNotEmpty) 'filter_logic': logic,
    for (var i = 0; i < rules.length; i++) ...{
      'filters[$i][field]': rules[i].field,
      'filters[$i][operator]': rules[i].operator,
      if (rules[i].needsValue) 'filters[$i][value]': rules[i].value.trim(),
    },
  };

  String path(String object, String search, int page) => Uri(
    path: '/objects/$object',
    queryParameters: {
      'q': search.trim(),
      'page': '$page',
      'per_page': '50',
      ...query,
    },
  ).toString();
}

String? validateFilterValue(Map<String, dynamic> field, RecordFilter rule) {
  if (!rule.needsValue) return null;
  if (rule.value.trim().isEmpty) return '请填写筛选值';
  final values = rule.operator == 'between'
      ? rule.value.split('..')
      : [rule.value];
  if (values.any((v) => v.trim().isEmpty) ||
      (rule.operator == 'between' && values.length != 2)) {
    return '请填写完整范围';
  }
  if (['number', 'range'].contains(field['type'])) {
    final numbers = values.map((v) => num.tryParse(v.trim())).toList();
    if (numbers.any((v) => v == null || !v.isFinite)) return '请输入有效数字';
    if (numbers.length == 2 && numbers[0]! > numbers[1]!) return '起始值不能大于结束值';
  }
  if (field['type'] == 'date') {
    for (final value in values) {
      final date = DateTime.tryParse(value);
      if (date == null || date.toIso8601String().substring(0, 10) != value) {
        return '请选择有效日期';
      }
    }
    if (values.length == 2 && values[0].compareTo(values[1]) > 0) {
      return '开始日期不能晚于结束日期';
    }
  }
  return null;
}

class ListFilterBar extends StatelessWidget {
  final PalantirApi api;
  final List<Map<String, dynamic>> fields;
  final Map<String, dynamic> relationOptions;
  final ListFilters value;
  final ValueChanged<ListFilters> onChanged;
  const ListFilterBar({
    super.key,
    required this.api,
    required this.fields,
    required this.relationOptions,
    required this.value,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            OutlinedButton.icon(
              key: const Key('open-list-filters'),
              icon: const Icon(Icons.filter_alt_outlined, size: 20),
              label: Text(
                value.rules.isEmpty ? '筛选' : '筛选（${value.rules.length}）',
              ),
              onPressed: filterFields(fields).isEmpty
                  ? null
                  : () async {
                      final next = await showModalBottomSheet<ListFilters>(
                        context: context,
                        isScrollControlled: true,
                        useSafeArea: true,
                        builder: (_) => FilterSheet(
                          api: api,
                          fields: filterFields(fields),
                          relationOptions: relationOptions,
                          initial: value,
                        ),
                      );
                      if (next != null && context.mounted) onChanged(next);
                    },
            ),
            if (value.rules.isNotEmpty) ...[
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  value.logic == 'or' ? '满足任一条件' : '满足全部条件',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
              TextButton(
                onPressed: () => onChanged(const ListFilters()),
                child: const Text('清空'),
              ),
            ],
          ],
        ),
        if (value.rules.isNotEmpty)
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                for (var i = 0; i < value.rules.length; i++)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: InputChip(
                      label: Text(_summary(value.rules[i])),
                      onDeleted: () => onChanged(
                        ListFilters(
                          logic: value.logic,
                          rules: [...value.rules]..removeAt(i),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
      ],
    ),
  );

  String _summary(RecordFilter rule) {
    final field = fields.where((f) => f['key'] == rule.field).firstOrNull;
    return '${field?['label'] ?? rule.field} ${filterLabels[rule.operator] ?? rule.operator}'
        '${rule.needsValue ? ' ${rule.label.isEmpty ? rule.value.replaceAll('..', ' 至 ') : rule.label}' : ''}';
  }
}

class FilterSheet extends StatefulWidget {
  final PalantirApi api;
  final List<Map<String, dynamic>> fields;
  final Map<String, dynamic> relationOptions;
  final ListFilters initial;
  const FilterSheet({
    super.key,
    required this.api,
    required this.fields,
    required this.relationOptions,
    required this.initial,
  });
  @override
  State<FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<FilterSheet> {
  final form = GlobalKey<FormState>();
  late List<RecordFilter> rules = [...widget.initial.rules];
  late String logic = widget.initial.logic;
  late final fields = [...widget.fields]
    ..sort((a, b) {
      const priorities = [
        'overall_status',
        'contract_status',
        'business_owner_user_id',
        'customer_id',
        'level',
        'customer_nature',
        'address',
      ];
      int rank(Map<String, dynamic> f) {
        final i = priorities.indexOf(f['key']);
        return i < 0 ? priorities.length : i;
      }

      return rank(a).compareTo(rank(b));
    });

  @override
  void initState() {
    super.initState();
    if (rules.isEmpty && fields.isNotEmpty) add();
  }

  void add() {
    final field = fields.first;
    rules.add(RecordFilter(field['key'], filterOperators(field).first));
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
    child: SizedBox(
      height:
          MediaQuery.sizeOf(context).height * .85 -
          MediaQuery.viewInsetsOf(context).bottom,
      child: SafeArea(
        top: false,
        child: Form(
          key: form,
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 12, 8, 8),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text(
                        '筛选条件',
                        style: TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                    TextButton(
                      onPressed: () => Navigator.pop(context),
                      child: const Text('取消'),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  children: [
                    if (rules.length > 1)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: DropdownButtonFormField<String>(
                          initialValue: logic,
                          decoration: const InputDecoration(labelText: '条件关系'),
                          items: const [
                            DropdownMenuItem(
                              value: 'and',
                              child: Text('满足全部条件'),
                            ),
                            DropdownMenuItem(
                              value: 'or',
                              child: Text('满足任一条件'),
                            ),
                          ],
                          onChanged: (v) => setState(() => logic = v!),
                        ),
                      ),
                    for (var i = 0; i < rules.length; i++) _row(i),
                    if (rules.length < 10)
                      OutlinedButton.icon(
                        onPressed: () => setState(add),
                        icon: const Icon(Icons.add),
                        label: const Text('添加条件'),
                      ),
                    const SizedBox(height: 16),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    OutlinedButton(
                      onPressed: () => setState(() {
                        rules.clear();
                        logic = 'and';
                      }),
                      child: const Text('重置'),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton(
                        onPressed: () {
                          if (form.currentState!.validate()) {
                            Navigator.pop(
                              context,
                              ListFilters(
                                rules: List.unmodifiable(rules),
                                logic: logic,
                              ),
                            );
                          }
                        },
                        child: const Text('应用筛选'),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    ),
  );

  Widget _row(int i) {
    final rule = rules[i];
    final field = fields.firstWhere((f) => f['key'] == rule.field);
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          children: [
            Row(
              children: [
                Expanded(child: Text('条件 ${i + 1}')),
                IconButton(
                  tooltip: '移除条件 ${i + 1}',
                  onPressed: () => setState(() => rules.removeAt(i)),
                  icon: const Icon(Icons.close, size: 20),
                ),
              ],
            ),
            DropdownButtonFormField<String>(
              key: ValueKey('filter-field-$i-${rule.field}'),
              initialValue: rule.field,
              isExpanded: true,
              menuMaxHeight: 320,
              decoration: const InputDecoration(labelText: '筛选字段'),
              items: fields
                  .map(
                    (f) => DropdownMenuItem<String>(
                      value: f['key'],
                      child: Text(
                        textOf(f['label']),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  )
                  .toList(),
              onChanged: (key) => setState(() {
                final next = fields.firstWhere((f) => f['key'] == key);
                rules[i] = RecordFilter(key!, filterOperators(next).first);
              }),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey(
                'filter-operator-$i-${rule.field}-${rule.operator}',
              ),
              initialValue: rule.operator,
              isExpanded: true,
              decoration: const InputDecoration(labelText: '判断条件'),
              items: filterOperators(field)
                  .map(
                    (op) => DropdownMenuItem(
                      value: op,
                      child: Text(filterLabels[op]!),
                    ),
                  )
                  .toList(),
              onChanged: (op) =>
                  setState(() => rules[i] = RecordFilter(rule.field, op!)),
            ),
            if (rule.needsValue) ...[
              const SizedBox(height: 12),
              _value(i, field, rule),
            ],
          ],
        ),
      ),
    );
  }

  Widget _value(int i, Map<String, dynamic> field, RecordFilter rule) {
    final key = ValueKey('filter-value-$i-${rule.field}-${rule.operator}');
    if (['relation', 'creatable_relation', 'account'].contains(field['type'])) {
      final options = mapOf(widget.relationOptions[rule.field]);
      return FormField<String>(
        key: key,
        validator: (_) => validateFilterValue(field, rules[i]),
        builder: (state) => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            InlineRelationField(
              api: widget.api,
              label: '筛选值',
              options: {
                ...options,
                'selectedItems': [
                  ...maps(options['selectedItems']),
                  if (rule.value.isNotEmpty)
                    {
                      'id': rule.value,
                      'label': rule.label.isEmpty ? rule.value : rule.label,
                    },
                ],
              },
              selected: rule.value.isEmpty ? [] : [rule.value],
              onChanged: (items) => setState(() {
                rules[i] = rule.withValue(
                  items.isEmpty ? '' : '${items.first['id']}',
                  items.isEmpty
                      ? ''
                      : textOf(
                          items.first['label'] ??
                              items.first['name'] ??
                              items.first['title'],
                        ),
                );
                state.didChange(rules[i].value);
              }),
            ),
            if (state.hasError)
              Text(
                state.errorText!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
          ],
        ),
      );
    }
    if (field['type'] == 'select') {
      final options = (field['options'] as List? ?? [])
          .map((e) => '$e')
          .toSet();
      if (rule.value.isNotEmpty) options.add(rule.value);
      return DropdownButtonFormField<String>(
        key: key,
        initialValue: rule.value.isEmpty ? null : rule.value,
        isExpanded: true,
        menuMaxHeight: 300,
        decoration: const InputDecoration(labelText: '筛选值'),
        items: options
            .map((v) => DropdownMenuItem(value: v, child: Text(v)))
            .toList(),
        validator: (_) => validateFilterValue(field, rules[i]),
        onChanged: (v) => setState(() => rules[i] = rule.withValue(v ?? '')),
      );
    }
    final between = rule.operator == 'between';
    final parts = between ? rule.value.split('..') : [rule.value];
    return FormField<String>(
      key: key,
      validator: (_) => validateFilterValue(field, rules[i]),
      builder: (state) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var j = 0; j < (between ? 2 : 1); j++)
            Padding(
              padding: EdgeInsets.only(top: j == 0 ? 0 : 12),
              child: _input(
                field,
                j < parts.length ? parts[j] : '',
                between ? (j == 0 ? '起始值' : '结束值') : '筛选值',
                (value) {
                  final values = between
                      ? [...rules[i].value.split('..')]
                      : [''];
                  while (values.length < (between ? 2 : 1)) {
                    values.add('');
                  }
                  values[j] = value;
                  rules[i] = rules[i].withValue(
                    values.join(between ? '..' : ''),
                  );
                  state.didChange(rules[i].value);
                },
              ),
            ),
          if (state.hasError)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                state.errorText!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
        ],
      ),
    );
  }

  Widget _input(
    Map<String, dynamic> field,
    String value,
    String label,
    ValueChanged<String> changed,
  ) {
    if (field['type'] == 'date') {
      return TextFormField(
        key: ValueKey('$label-$value'),
        initialValue: value,
        readOnly: true,
        decoration: InputDecoration(
          labelText: label,
          hintText: '选择日期',
          suffixIcon: const Icon(Icons.calendar_today_outlined),
        ),
        onTap: () async {
          final date = await showDatePicker(
            context: context,
            initialDate: DateTime.tryParse(value) ?? DateTime.now(),
            firstDate: DateTime(1900),
            lastDate: DateTime(2100, 12, 31),
          );
          if (date != null && mounted) {
            setState(() => changed(date.toIso8601String().substring(0, 10)));
          }
        },
      );
    }
    return TextFormField(
      initialValue: value,
      decoration: InputDecoration(labelText: label),
      keyboardType: ['number', 'range'].contains(field['type'])
          ? const TextInputType.numberWithOptions(decimal: true, signed: true)
          : TextInputType.text,
      onChanged: changed,
    );
  }
}

import 'package:flutter/material.dart';
import 'api.dart';
import 'ui.dart';
import 'assistant.dart';

class ProcurementPage extends StatefulWidget {
 final PalantirApi api;
 const ProcurementPage({super.key, required this.api});
 @override State<ProcurementPage> createState()=>_ProcurementPageState();
}
class _ProcurementPageState extends State<ProcurementPage> {
 String search='',method='',time='published',start='',end='';
 int page=1,revision=0;
 final queryController=TextEditingController();
 @override void dispose(){queryController.dispose();super.dispose();}
 Future<void> dates() async {
  final range=await showDateRangePicker(context:context,firstDate:DateTime(2020),lastDate:DateTime(2100));
  if(range!=null && mounted){setState((){start=range.start.toIso8601String().substring(0,10);end=range.end.toIso8601String().substring(0,10);page=1;});}
 }
 @override Widget build(BuildContext context)=>Scaffold(
  appBar:AppBar(title:const Text('招采信息'),actions:[IconButton(tooltip:'刷新',onPressed:()=>setState(()=>revision++),icon:const Icon(Icons.refresh))]),
  body:Column(children:[Padding(padding:const EdgeInsets.all(16),child:Column(children:[
   TextField(controller:queryController,decoration:const InputDecoration(labelText:'项目、单位或采购编号',prefixIcon:Icon(Icons.search)),onSubmitted:(v)=>setState((){search=v;page=1;})),
   const SizedBox(height:10),
   Row(children:[Expanded(child:DropdownButtonFormField<String>(initialValue:time,isExpanded:true,decoration:const InputDecoration(labelText:'时间口径'),items:const [DropdownMenuItem(value:'published',child:Text('公告发布时间')),DropdownMenuItem(value:'registration',child:Text('报名截止')),DropdownMenuItem(value:'deadline',child:Text('报价 / 投标截止'))],onChanged:(v)=>setState((){time=v!;page=1;}))),const SizedBox(width:8),TextButton(onPressed:dates,child:const Text('选择日期'))]),
   if(start.isNotEmpty)Row(children:[Expanded(child:Text('$start 至 $end',style:const TextStyle(fontSize:12))),TextButton(onPressed:()=>setState((){start='';end='';page=1;}),child:const Text('清除'))]),
  ])),Expanded(child:AsyncPage(key:ValueKey('$search-$method-$time-$start-$end-$page-$revision'),load:()=>widget.api.page(Uri(path:'/procurement-hub',queryParameters:{'q':search,'method':method,'time':time,'start':start,'end':end,'page':'$page'}).toString()),builder:(data){
   final pagination=mapOf(data['notices']);final records=maps(pagination['data']);final methods=(data['methods'] as List? ?? []).cast<String>();final totals=mapOf(data['totals']);
   return ListView(padding:const EdgeInsets.fromLTRB(16,0,16,24),children:[
    DropdownButtonFormField<String>(initialValue:method,isExpanded:true,decoration:const InputDecoration(labelText:'采购方式'),items:[const DropdownMenuItem(value:'',child:Text('全部采购方式')),...methods.map((m)=>DropdownMenuItem(value:m,child:Text(m)))],onChanged:(v)=>setState((){method=v!;page=1;})),
    Padding(padding:const EdgeInsets.symmetric(vertical:14),child:Text('匹配 ${totals['count'] ?? 0} 条 · 最新公告优先')),
    if(records.isEmpty)note('暂无匹配信息，请调整筛选或通过 PC Codex 发布。'),
    ...records.map((n){final p=mapOf(n['payload']);return Padding(padding:const EdgeInsets.only(bottom:12),child:Card(child:InkWell(onTap:()=>push(context,NoticeDetail(api:widget.api,id:'${n['id']}')),child:Padding(padding:const EdgeInsets.all(16),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
      Wrap(spacing:6,children:[Chip(label:Text(textOf(p['priority']))),Chip(label:Text(textOf(p['nature'])))]),
      Text(textOf(n['title']),style:const TextStyle(fontSize:17,fontWeight:FontWeight.w600)),const SizedBox(height:8),Text(textOf(p['unit']),style:const TextStyle(fontSize:12)),
      const Divider(),kv('采购数量',p['quantity']),kv('报价 / 投标截止',p['deadline']),kv('报名 / 文件获取',p['registration']),
      if(n['has_issues']==true)note('有核实提示，查看详情了解差异。'),
      Text('发布于 ${textOf(p['published'])}',style:const TextStyle(fontSize:12)),
    ])))));}),
    Row(mainAxisAlignment:MainAxisAlignment.spaceBetween,children:[TextButton(onPressed:page>1?()=>setState(()=>page--):null,child:const Text('上一页')),Text('$page / ${pagination['last_page'] ?? 1}'),TextButton(onPressed:pagination['next_page_url']!=null?()=>setState(()=>page++):null,child:const Text('下一页'))]),
    note('采集端初判不代表企业已具备投标资格。内容未经过平台原文核验。'),
   ]);
  }))]));
}
class NoticeDetail extends StatelessWidget {
 final PalantirApi api;final String id;
 const NoticeDetail({super.key,required this.api,required this.id});
 @override Widget build(BuildContext context)=>Scaffold(appBar:AppBar(title:const Text('采购详情')),body:AsyncPage(load:()=>api.page('/procurement-hub/notices/$id'),builder:(data){final n=mapOf(data['notice']),p=mapOf(n['payload']);return ListView(padding:const EdgeInsets.all(20),children:[
 Text(textOf(p['title']),style:const TextStyle(fontSize:24,fontWeight:FontWeight.w600)),const SizedBox(height:16),
 if('${p['issues'] ?? ''}'.isNotEmpty)note('核实提示：${p['issues']}'),
 panel(Column(children:[kv('采购单位',p['unit']),kv('采购编号',p['code']),kv('采购方式',p['nature']),kv('物资类别',p['category']),kv('采购性质',p['subtype']),kv('优先级',p['priority']),kv('纳入状态',p['status']),kv('框架协议',p['framework']==true?'是':'否'),kv('公告发布时间',p['published']),kv('报名 / 文件获取',p['registration']),kv('报价 / 投标截止',p['deadline']),kv('采购数量',p['quantity']),kv('物资及规格',p['material']),kv('商务与资格条件',p['terms']),kv('联系方式',p['contacts'])])),const SizedBox(height:16),
 panel(Column(children:[kv('采集端初判',p['judgment']),kv('来源平台',p['platform']),kv('来源链接',p['url']),kv('来源文件引用（未上传）',(p['evidence'] as List? ?? []).join('；'))])),
 ...((data['warnings'] as List? ?? []).map((w)=>note('$w'))),
 note('可在平台 AI 助手中要求分析招采记录 #$id，并与其他采购机会比较。'),
 if(api.can('ai.harness.view')) FilledButton.icon(onPressed:()=>openFlow(context,AssistantPage(api:api)),icon:const Icon(Icons.auto_awesome),label:const Text('进入平台 AI 分析')),
 ]);}));
}

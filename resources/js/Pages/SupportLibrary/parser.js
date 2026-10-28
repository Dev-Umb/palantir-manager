
function parseGroupCombination(expression){
  const text=expression.normalize('NFKC').trim();
  if(!text)throw Error('请输入“高度组合@横向跨距@纵向跨距/组数”，例如6+5@2+3@3+3/4。');
  const groups=[],safe=n=>{if(!Number.isSafeInteger(n)||n<=0)throw Error('尺寸或组数超出可精确计算范围，且必须为正数。');return n;};
  function dimensions(field){return field.split('+').map(token=>{const value=token.trim();if(!/^\d+(?:\.\d+)?$/.test(value))throw Error('高度和跨距须为正数，多段用+连接，单位为米。');const[whole,fraction='']=value.split('.');if(/[1-9]/.test(fraction.slice(3)))throw Error('尺寸须精确到毫米。');return safe(Number(whole)*1000+Number(fraction.slice(0,3).padEnd(3,'0')));});}
  const terms=text.replace(/[^\S\r\n]*([+@/])[^\S\r\n]*/g,'$1').split(/[,，、;；]/).flatMap(chunk=>chunk.trim().split(/\s+/));
  for(const raw of terms){
    const term=raw.trim(),slash=term.split('/');
    const fields=slash[0].split('@').map(v=>v.trim());
    let count=slash.length===1?'1':slash[1].trim();
    // Preserve older saved expressions; otherwise an omitted /count means one group.
    if(slash.length===1&&fields.length===4)count=fields.pop();
    if(slash.length>2||![1,3].includes(fields.length)||!/^([1-9]\d*)$/.test(count))throw Error(`无法解析“${term}”。格式为高度组合@横向跨距@纵向跨距/组数，或单柱高度组合/组数；省略/组数时默认1组，组数须为正整数。不同支架组合请用空格、分号或换行分隔。`);
    const column_only=fields.length===1,heights=dimensions(fields[0]),xs=column_only?[]:dimensions(fields[1]),ys=column_only?[]:dimensions(fields[2]);
    groups.push({column_only,height_segments_mm:heights,transverse_spans_mm:xs,longitudinal_spans_mm:ys,
      height_mm:safe(heights.reduce((a,b)=>a+b,0)),transverse_width_mm:column_only?0:safe(xs.reduce((a,b)=>a+b,0)),longitudinal_length_mm:column_only?0:safe(ys.reduce((a,b)=>a+b,0)),
      transverse_spacing_mm:xs.length===1?xs[0]:null,longitudinal_spacing_mm:ys.length===1?ys[0]:null,
      groups:safe(Number(count)),expression:term});
  }
  safe(groups.reduce((s,g)=>s+g.groups,0));return groups;
}

export { parseGroupCombination };

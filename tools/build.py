import json, os, re, html
K=os.environ.get('K',os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
g=json.load(open(K+'/api/gallery-default.json',encoding='utf-8'))
s=open(K+'/template.html',encoding='utf-8').read()
# domyślne treści strony = teksty z szablonu (data-t / data-tp) + listy z api/site-base.json
base=json.load(open(K+'/api/site-base.json',encoding='utf-8'))
def totext(h):
    h=re.sub(r'\s*<br\s*/?>\s*','\n',h); h=re.sub(r'</?em>','*',h); h=re.sub(r'<[^>]+>','',h)
    return html.unescape(re.sub(r'[ \t]*\n[ \t]*','\n',re.sub(r'[ \t\r]+',' ',h)).strip())
T={}
for m in re.finditer(r'<(\w+)\b[^>]*\sdata-t="([\w]+)"[^>]*>(.*?)</\1>',s,re.S):
    T.setdefault(m.group(2),totext(m.group(3)))
for m in re.finditer(r'<div data-tp="(\w+)">(.*?)</div>',s,re.S):
    T[m.group(1)]='\n\n'.join(totext(p) for p in re.findall(r'<p>(.*?)</p>',m.group(2),re.S))
base['t']=T
base['seo']['desc']=html.unescape(re.search(r'<meta name="description" content="([^"]*)"',s).group(1))
json.dump(base,open(K+'/api/site-default.json','w',encoding='utf-8'),ensure_ascii=False,indent=1)
assert s.count('/*GAL*/[]')==1 and s.count('/*SITEDEF*/null')==1 and s.count('/*BREEDS*/null')==1
br=json.load(open(K+'/api/breeds-default.json',encoding='utf-8'))
s=s.replace('/*BREEDS*/null',json.dumps(br,ensure_ascii=False,separators=(',',':')).replace('</','<\\/'))
s=s.replace('/*GAL*/[]',json.dumps(g,ensure_ascii=False,separators=(',',':')))
s=s.replace('/*SITEDEF*/null',json.dumps(base,ensure_ascii=False,separators=(',',':')).replace('</','<\\/'))
open(K+'/index.html','w',encoding='utf-8').write(s)
print('built',len(s),'texts',len(T))

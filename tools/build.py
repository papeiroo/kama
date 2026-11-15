import json
import os
K=os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
g=json.load(open(K+'/api/gallery-default.json',encoding='utf-8'))
s=open(K+'/template.html',encoding='utf-8').read()
assert s.count('/*GAL*/[]')==1
s=s.replace('/*GAL*/[]',json.dumps(g,ensure_ascii=False,separators=(',',':')))
open(K+'/index.html','w',encoding='utf-8').write(s)
print('built',len(s))

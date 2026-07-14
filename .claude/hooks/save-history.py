#!/usr/bin/env python3
import json
import sys
import os
from datetime import datetime

try:
    input_data = json.load(sys.stdin)
except Exception:
    sys.exit(0)

if input_data.get('stop_hook_active'):
    sys.exit(0)

transcript_path = input_data.get('transcript_path', '')
cwd = input_data.get('cwd', os.getcwd())

if not transcript_path or not os.path.exists(transcript_path):
    sys.exit(0)

messages = []
try:
    with open(transcript_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                obj = json.loads(line)
                if not isinstance(obj, dict):
                    continue
                if 'role' in obj:
                    messages.append(obj)
                elif 'message' in obj and isinstance(obj['message'], dict):
                    messages.append(obj['message'])
                elif obj.get('type') in ('user', 'assistant'):
                    messages.append({'role': obj['type'], 'content': obj.get('content', '')})
            except Exception:
                pass
except Exception:
    sys.exit(0)

last_user_msg = None
for msg in reversed(messages):
    if not isinstance(msg, dict) or msg.get('role') != 'user':
        continue
    content = msg.get('content', '')
    if isinstance(content, str):
        last_user_msg = content.strip()
    elif isinstance(content, list):
        parts = [item.get('text', '') for item in content if isinstance(item, dict) and item.get('type') == 'text']
        last_user_msg = ' '.join(parts).strip()
    if last_user_msg:
        break

if not last_user_msg:
    sys.exit(0)

model_info = input_data.get('model', {})
if isinstance(model_info, dict):
    raw = model_info.get('display_name', '') or model_info.get('id', '') or 'Sonnet 4.6'
    model_name = raw.replace('Claude ', '')
else:
    model_name = 'Sonnet 4.6'

date_str = datetime.now().strftime('%Y-%m-%d')
history_dir = os.path.join(cwd, '.claude', 'history')
os.makedirs(history_dir, exist_ok=True)
history_file = os.path.join(history_dir, f'{date_str}.md')

last_num = 0
if os.path.exists(history_file):
    with open(history_file, 'r', encoding='utf-8') as f:
        for line in f:
            stripped = line.strip()
            if stripped and stripped[0].isdigit() and '.' in stripped:
                try:
                    num = int(stripped.split('.')[0])
                    if num > last_num:
                        last_num = num
                except Exception:
                    pass

clean_msg = ' '.join(last_user_msg.split())
if len(clean_msg) > 1000:
    clean_msg = clean_msg[:997] + '...'

with open(history_file, 'a', encoding='utf-8') as f:
    f.write(f'{last_num + 1}. {clean_msg} _({model_name})_\n')

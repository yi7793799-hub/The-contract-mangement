#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
PDF文本提取脚本
使用PyMuPDF提取PDF文本内容，输出到文件
支持将扫描版PDF渲染为图片
支持通过临时文件传递路径（解决中文路径编码问题）
"""
import fitz
import json
import sys
import os

# 设置控制台编码
if sys.platform == 'win32':
    import locale
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except:
        pass

def extract_pdf(pdf_path, output_path):
    """提取PDF文本并写入文件"""
    result = {
        'text': '',
        'pages': 0,
        'structure': [],
        'has_tables': False
    }

    try:
        doc = fitz.open(pdf_path)
        result['pages'] = len(doc)

        all_text = []
        for page_num, page in enumerate(doc):
            # 提取文本
            text = page.get_text()
            if text.strip():
                all_text.append(f"--- 第{page_num + 1}页 ---")
                all_text.append(text.strip())

            # 检测表格
            tables = page.find_tables()
            if tables.tables:
                result['has_tables'] = True
                for table in tables.tables:
                    table_data = table.extract()
                    if table_data:
                        result['structure'].append({
                            'type': 'table',
                            'page': page_num + 1,
                            'content': table_data
                        })

        result['text'] = '\n'.join(all_text)
        doc.close()

    except Exception as e:
        result['error'] = str(e)

    # 写入文件（UTF-8编码）
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(result, f, ensure_ascii=False, indent=2)

def render_pdf_to_images(pdf_path, output_dir, dpi=150, max_pages=None):
    """将PDF页面渲染为图片"""
    result = {
        'images': [],
        'error': None
    }

    try:
        doc = fitz.open(pdf_path)
        total_pages = len(doc)

        if max_pages:
            pages_to_render = min(max_pages, total_pages)
        else:
            pages_to_render = total_pages

        for i in range(pages_to_render):
            page = doc[i]
            pix = page.get_pixmap(dpi=dpi)
            img_path = os.path.join(output_dir, f'page_{i}.png')
            pix.save(img_path)
            result['images'].append(img_path)

        doc.close()

    except Exception as e:
        result['error'] = str(e)

    return result

def main():
    if len(sys.argv) < 3:
        print(json.dumps({'error': '请提供PDF文件路径和输出文件路径'}, ensure_ascii=False))
        return

    # 获取参数
    arg1 = sys.argv[1]
    output_path = sys.argv[2]

    # 判断 arg1 是路径文件还是直接路径
    # 如果是 .txt 文件，则从中读取真实路径
    if arg1.endswith('.txt') and os.path.exists(arg1):
        with open(arg1, 'r', encoding='utf-8') as f:
            pdf_path = f.read().strip()
    else:
        pdf_path = arg1

    # 检查文件是否存在
    if not os.path.exists(pdf_path):
        print(json.dumps({'error': '文件不存在', 'path': pdf_path}, ensure_ascii=False))
        return

    # 检查是否有额外参数表示渲染图片
    if len(sys.argv) > 3 and sys.argv[3] == '--render':
        output_dir = sys.argv[4] if len(sys.argv) > 4 else os.path.dirname(output_path)
        result = render_pdf_to_images(pdf_path, output_dir)
        with open(output_path, 'w', encoding='utf-8') as f:
            json.dump(result, f, ensure_ascii=False, indent=2)
    else:
        extract_pdf(pdf_path, output_path)

if __name__ == '__main__':
    main()
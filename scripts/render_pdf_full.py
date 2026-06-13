#!/usr/bin/env python3
"""
PDF渲染脚本 - 将PDF页面渲染为图片
用于扫描版PDF的OCR识别
"""

import sys
import os

def render_pdf_to_images(pdf_path, output_dir, max_pages=20):
    """
    将PDF渲染为PNG图片

    Args:
        pdf_path: PDF文件路径
        output_dir: 输出目录
        max_pages: 最大页数限制

    Returns:
        生成的图片数量
    """
    try:
        import fitz  # PyMuPDF
    except ImportError:
        print("ERROR: PyMuPDF not installed. Run: pip install pymupdf", file=sys.stderr)
        return 0

    if not os.path.exists(pdf_path):
        print(f"ERROR: PDF file not found: {pdf_path}", file=sys.stderr)
        return 0

    if not os.path.exists(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    try:
        doc = fitz.open(pdf_path)
    except Exception as e:
        print(f"ERROR: Cannot open PDF: {e}", file=sys.stderr)
        return 0

    page_count = min(len(doc), max_pages)
    generated = 0

    for i in range(page_count):
        try:
            page = doc[i]
            # 渲染为300 DPI图片
            mat = fitz.Matrix(300 / 72, 300 / 72)
            pix = page.get_pixmap(matrix=mat)

            output_path = os.path.join(output_dir, f"page_{i+1:03d}.png")
            pix.save(output_path)
            generated += 1
        except Exception as e:
            print(f"WARNING: Failed to render page {i+1}: {e}", file=sys.stderr)

    doc.close()
    return generated


if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python render_pdf_full.py <pdf_path> <output_dir> [max_pages]", file=sys.stderr)
        sys.exit(1)

    pdf_path = sys.argv[1]
    output_dir = sys.argv[2]
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 20

    count = render_pdf_to_images(pdf_path, output_dir, max_pages)
    print(f"Rendered {count} pages")
    sys.exit(0 if count > 0 else 1)

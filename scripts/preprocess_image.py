"""
合同图像预处理脚本
用于增强图像质量以提高OCR识别准确率
"""
import sys
import os
from PIL import Image, ImageEnhance, ImageFilter


def preprocess(input_path: str, output_path: str) -> None:
    """
    图像预处理主函数

    步骤:
    1. 灰度化
    2. 增强对比度 (1.5x)
    3. 降噪 (MedianFilter)
    4. 锐化
    """
    if not os.path.exists(input_path):
        raise FileNotFoundError(f"输入文件不存在: {input_path}")

    with Image.open(input_path) as img:
        # 1. 灰度化（如果是彩色图像）
        if img.mode != 'L':
            img = img.convert('L')

        # 2. 增强对比度
        enhancer = ImageEnhance.Contrast(img)
        img = enhancer.enhance(1.5)

        # 3. 降噪
        img = img.filter(ImageFilter.MedianFilter(3))

        # 4. 锐化
        img = img.filter(ImageFilter.SHARPEN)

        # 确保输出目录存在
        output_dir = os.path.dirname(output_path)
        if output_dir and not os.path.exists(output_dir):
            os.makedirs(output_dir)

        img.save(output_path)


def preprocess_image(input_path: str, output_path: str) -> str:
    """
    图像预处理（带返回值，方便PHP调用）

    Returns:
        str: 输出文件路径
    """
    preprocess(input_path, output_path)
    return output_path


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Usage: python preprocess_image.py <input_path> <output_path>")
        sys.exit(1)

    input_path = sys.argv[1]
    output_path = sys.argv[2]

    try:
        result = preprocess_image(input_path, output_path)
        print(result)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)

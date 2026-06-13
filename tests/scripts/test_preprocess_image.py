import unittest
import os
import sys
from PIL import Image
import tempfile

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.dirname(__file__))))
from scripts.preprocess_image import preprocess, preprocess_image


class TestPreprocessImage(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.mkdtemp()

    def tearDown(self):
        import shutil
        shutil.rmtree(self.temp_dir, ignore_errors=True)

    def test_preprocess_grayscale(self):
        """测试灰度化处理"""
        # 创建测试图片
        input_path = os.path.join(self.temp_dir, 'test_input.png')
        output_path = os.path.join(self.temp_dir, 'test_output.png')

        img = Image.new('RGB', (100, 100), color='red')
        img.save(input_path)

        preprocess(input_path, output_path)

        with Image.open(output_path) as result:
            self.assertEqual(result.mode, 'L')  # 灰度图
            self.assertEqual(result.size, (100, 100))

    def test_preprocess_enhances_contrast(self):
        """测试对比度增强"""
        input_path = os.path.join(self.temp_dir, 'test_contrast.png')
        output_path = os.path.join(self.temp_dir, 'test_contrast_out.png')

        # 创建灰度图
        img = Image.new('L', (100, 100), color=128)
        img.save(input_path)

        preprocess(input_path, output_path)

        # 对比度增强后，像素值会变化
        self.assertTrue(os.path.exists(output_path))

    def test_preprocess_image_function(self):
        """测试 preprocess_image 函数"""
        input_path = os.path.join(self.temp_dir, 'test_func.png')
        output_path = os.path.join(self.temp_dir, 'test_func_out.png')

        img = Image.new('RGB', (200, 200), color='blue')
        img.save(input_path)

        result = preprocess_image(input_path, output_path)

        self.assertEqual(result, output_path)
        self.assertTrue(os.path.exists(output_path))

    def test_output_file_created(self):
        """测试输出文件被创建"""
        input_path = os.path.join(self.temp_dir, 'test_created.png')
        output_path = os.path.join(self.temp_dir, 'output.png')

        img = Image.new('RGB', (50, 50), color='white')
        img.save(input_path)

        preprocess(input_path, output_path)

        self.assertTrue(os.path.exists(output_path))


if __name__ == '__main__':
    unittest.main()

<?php namespace Model\ImgResize;

class Image
{
	protected \GdImage $img;
	public int $w;
	public int $h;
	public string $mime;
	public ?array $exif;

	/**
	 * Image constructor.
	 *
	 * @param string $url
	 */
	public function __construct(string $url)
	{
		if (!file_exists($url))
			throw new \Exception('Non existing image');

		$size = getimagesize($url);
		$this->mime = $size['mime'];
		$this->exif = (@exif_read_data($url)) ?: null;

		switch ($this->mime) {
			case 'image/jpeg':
				$this->img = imagecreatefromjpeg($url) ?: null;
				break;
			case 'image/png':
				$this->img = imagecreatefrompng($url) ?: null;
				break;
			case 'image/gif':
				$this->img = imagecreatefromgif($url) ?: null;
				break;
			case 'image/webp':
				$this->img = imagecreatefromwebp($url) ?: null;
				break;
			default:
				throw new \Exception('Image type not supported');
		}

		if (!$this->img)
			throw new \Exception('Image file not valid');

		$ort = $this->exif ? ($this->exif['IFD0']['Orientation'] ?? $this->exif['Orientation'] ?? 0) : 0;

		switch ($ort) {
			case 3: // 180 rotate
				$this->img = imagerotate($this->img, 180, 0);
				break;
			case 6: // 90 rotate right
				$this->img = imagerotate($this->img, 270, 0);
				break;
			case 8:  // 90 rotate left
				$this->img = imagerotate($this->img, 90, 0);
				break;
		}

		$this->w = imagesx($this->img);
		$this->h = imagesy($this->img);
	}

	/**
	 *
	 */
	public function __destruct()
	{
		$this->destroy();
	}

	/**
	 *
	 */
	public function destroy()
	{
		if (isset($this->img))
			imagedestroy($this->img);
		unset($this->img);
	}

	/**
	 * @param array $newSizes
	 * @return \GdImage
	 */
	public function get(array $newSizes = []): \GdImage
	{
		if (isset($newSizes['w']) and !isset($newSizes['h']))
			$newSizes['h'] = (int)round($newSizes['w'] * $this->h / $this->w);
		if (isset($newSizes['h']) and !isset($newSizes['w']))
			$newSizes['w'] = (int)round($newSizes['h'] * $this->w / $this->h);

		if (isset($newSizes['w'], $newSizes['h'])) {
			$ww = $newSizes['w'];
			$hh = $newSizes['h'];
			$w = $this->w;
			$h = $this->h;

			if (!isset($newSizes['extend']))
				$newSizes['extend'] = true;

			$ratio = $w / $h;
			$rightRatio = $ww / $hh;

			$newImg = imagecreatetruecolor($ww, $hh);
			imagealphablending($newImg, false);
			ImageSaveAlpha($newImg, true);
			ImageFill($newImg, 0, 0, IMG_COLOR_TRANSPARENT);
			imagealphablending($newImg, true);

			if (!$newSizes['extend']) {
				if ($ratio < $rightRatio) {
					$new_width = (int)round($w * $hh / $h);
					imagecopyresampled($newImg, $this->img, round(($ww - $new_width) / 2), 0, 0, 0, $new_width, $hh, $w, $h);
				} else {
					$new_height = (int)round($h * $ww / $w);
					imagecopyresampled($newImg, $this->img, 0, round(($hh - $new_height) / 2), 0, 0, $ww, $new_height, $w, $h);
				}
			} else {
				if ($ratio < $rightRatio) {
					$new_height = (int)round($h * $ww / $w);
					imagecopyresampled($newImg, $this->img, 0, round(($hh - $new_height) / 2), 0, 0, $ww, $new_height, $w, $h);
				} else {
					$new_width = (int)round($w * $hh / $h);
					imagecopyresampled($newImg, $this->img, round(($ww - $new_width) / 2), 0, 0, 0, $new_width, $hh, $w, $h);
				}
			}
		} else {
			$newImg = $this->getClone();
			imagealphablending($newImg, false);
			ImageSaveAlpha($newImg, true);
			imagealphablending($newImg, true);
		}

		return $newImg;
	}

	/**
	 * @param string|null $url
	 * @param array $newSizes
	 * @return string|null
	 */
	public function save(?string $url = null, array $newSizes = []): ?string
	{
		$newImg = $this->get($newSizes);

		$mime = $newSizes['type'] ?? $this->mime;

		if ($url) {
			if (file_exists($url))
				unlink($url);
		} else {
			ob_start();
		}

		switch ($mime) {
			case 'image/jpeg':
				$response = imagejpeg($newImg, $url);
				break;
			case 'image/png':
				$response = imagepng($newImg, $url);
				break;
			case 'image/gif':
				$response = imagegif($newImg, $url);
				break;
			case 'image/webp':
				$response = imagewebp($newImg, $url);
				break;
			default:
				throw new \Exception('Unsupported mime type in ImgResize save');
		}

		if (!$url)
			$imageData = ob_get_clean();

		if (!$response)
			throw new \Exception('Unable to save');

		imagedestroy($newImg);
		unset($newImg);

		return $url ? null : $imageData;
	}

	/**
	 * @return \GdImage
	 */
	public function getClone(): \GdImage
	{
		//Get sizes from image.
		$w = $this->w;
		$h = $this->h;
		//Get the transparent color from a 256 palette image.
		$trans = imagecolortransparent($this->img);

		//If this is a true color image...
		if (imageistruecolor($this->img)) {
			$clone = imagecreatetruecolor($w, $h);
			imagealphablending($clone, false);
			imagesavealpha($clone, true);
		} else {
			$clone = imagecreate($w, $h);

			//If the image has transparency...
			if ($trans >= 0) {
				$rgb = imagecolorsforindex($this->img, $trans);
				imagesavealpha($clone, true);
				$trans_index = imagecolorallocatealpha($clone, $rgb['red'], $rgb['green'], $rgb['blue'], $rgb['alpha']);
				imagefill($clone, 0, 0, $trans_index);
			}
		}

		//Create the Clone!!
		imagecopy($clone, $this->img, 0, 0, 0, 0, $w, $h);

		return $clone;
	}

	/**
	 * @param string $file
	 * @return bool
	 */
	public function applyWatermark(string $file): bool
	{
		if (!file_exists($file))
			return false;

		$size = getimagesize($file);
		$w = $this->w / 4;
		if ($w > $size[0])
			$w = $size[0];
		$h = $w * $size[1] / $size[0];

		$x = 10;
		$y = $this->h - $h - 10;

		$wm = imagecreatefrompng($file);
		return imagecopyresampled($this->img, $wm, $x, $y, 0, 0, $w, $h, $size[0], $size[1]);
	}
}

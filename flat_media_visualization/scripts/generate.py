#!/usr/bin/env python3
"""Stream media into small mono visualization files; requires ffmpeg and numpy."""
import argparse
import gzip
import json
import math
import shutil
import subprocess
from pathlib import Path

import numpy as np

SAMPLE_RATE = 16000
SLICES_PER_SECOND = 20
BINS = 128
FFT_SIZE = 1024
HOP = SAMPLE_RATE // SLICES_PER_SECOND
PEAKS_PER_SECOND = 100
MAX_DURATION = 4 * 60 * 60


def write_json(path, value):
    path.write_text(json.dumps(value, separators=(',', ':'), allow_nan=False))


def generate(source, output, ffmpeg='ffmpeg', ffprobe='ffprobe'):
    probe = subprocess.run([ffprobe, '-v', 'error', '-show_format', '-show_streams',
                            '-of', 'json', str(source)], check=True, capture_output=True, timeout=60)
    metadata = json.loads(probe.stdout)
    if not any(s.get('codec_type') == 'audio' for s in metadata['streams']):
        raise ValueError('The media has no audio track.')
    duration = float(metadata['format']['duration'])
    if not math.isfinite(duration) or not 0 < duration <= MAX_DURATION:
        raise ValueError('Supported duration is greater than zero and at most four hours.')
    output.mkdir(parents=True, exist_ok=True)
    frames = math.ceil(duration * SLICES_PER_SECOND)
    # Keep media time zero, including leading silence in delayed video audio tracks.
    command = [ffmpeg, '-nostdin', '-v', 'error', '-threads', '1', '-copyts', '-start_at_zero',
               '-i', str(source), '-map', '0:a:0', '-vn', '-ac', '1',
               '-af', f'aresample={SAMPLE_RATE}:async=1:first_pts=0,apad',
               '-t', str(duration), '-ar', str(SAMPLE_RATE), '-f', 'f32le', 'pipe:1']
    peaks = []
    window = np.hanning(HOP)
    with (output / 'ffmpeg.log').open('wb') as log:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=log)
        try:
            # Write each frequency slice directly; memory use does not grow with duration.
            with (output / 'spectrogram.json').open('w') as spectrum:
                spectrum.write('[[')  # channel -> time slice -> frequency bin
                for frame in range(frames):
                    raw = process.stdout.read(HOP * 4)
                    samples = np.frombuffer(raw, dtype='<f4').copy()
                    if len(samples) < HOP:
                        samples = np.pad(samples, (0, HOP - len(samples)))
                    samples = np.nan_to_num(samples, nan=0, posinf=0, neginf=0)
                    # 100 min/max pairs per second preserve short waveform transients.
                    for block in samples.reshape(5, SAMPLE_RATE // PEAKS_PER_SECOND):
                        peaks.extend([round(float(block.min()), 5), round(float(block.max()), 5)])
                    amplitude = np.abs(np.fft.rfft(samples * window, n=FFT_SIZE))[:FFT_SIZE // 2]
                    amplitude *= 2 / window.sum()
                    # Pool adjacent linear-frequency bins (0–8 kHz) into 128 bands.
                    amplitude = amplitude.reshape(BINS, -1).max(axis=1)
                    db = 20 * np.log10(np.maximum(amplitude, 1e-12))
                    intensity = np.rint(np.clip((db + 100) / 80, 0, 1) * 255).astype(np.uint8)
                    if frame:
                        spectrum.write(',')
                    spectrum.write(json.dumps(intensity.tolist(), separators=(',', ':')))
                spectrum.write(']]')
            process.stdout.close()
            if process.wait(timeout=60):
                raise RuntimeError('ffmpeg failed: ' + (output / 'ffmpeg.log').read_text()[-2000:])
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
    (output / 'ffmpeg.log').unlink()
    write_json(output / 'peaks.json', {'peaks': [peaks], 'duration': duration})
    for name in ('peaks', 'spectrogram'):
        with (output / f'{name}.json').open('rb') as src, gzip.open(output / f'{name}.json.gz', 'wb') as dst:
            shutil.copyfileobj(src, dst)
    # Written last; the Drupal worker atomically publishes the whole directory.
    write_json(output / 'manifest.json', {
        'duration': duration, 'sample_rate': SAMPLE_RATE, 'frequency_bins': BINS,
        'time_slices_per_second': SLICES_PER_SECOND, 'frequency_min': 0,
        'frequency_max': SAMPLE_RATE // 2, 'scale': 'linear',
    })


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--ffmpeg', default='ffmpeg')
    parser.add_argument('--ffprobe', default='ffprobe')
    args = parser.parse_args()
    generate(args.source, args.output, args.ffmpeg, args.ffprobe)

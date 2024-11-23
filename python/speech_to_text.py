#!/usr/bin/env python3

import sys
import json
import whisper
import argparse
from datetime import datetime
import numpy as np
import torch

class SpeechToTextConverter:
    def __init__(self, model_size="base"):
        self.model = whisper.load_model(model_size)

    def convert(self, audio_path: str, language: str = None) -> dict:
        try:
            # Transcribe audio
            options = {"language": language} if language else {}
            result = self.model.transcribe(audio_path, **options)

            # Format segments
            segments = []
            for segment in result["segments"]:
                segments.append({
                    "text": segment["text"].strip(),
                    "start": segment["start"],
                    "duration": segment["end"] - segment["start"],
                    "start_time": self.format_timestamp(segment["start"])
                })

            return {
                "success": True,
                "text": result["text"],
                "segments": segments,
                "language": result["language"],
                "duration": result["segments"][-1]["end"] if result["segments"] else 0,
                "metadata": {
                    "processed_at": datetime.now().isoformat(),
                    "model": "whisper",
                    "model_size": "base"
                }
            }

        except Exception as e:
            return {
                "success": False,
                "error": str(e),
                "error_type": type(e).__name__
            }

    def format_timestamp(self, seconds: float) -> str:
        hours = int(seconds // 3600)
        minutes = int((seconds % 3600) // 60)
        seconds = int(seconds % 60)
        
        if hours > 0:
            return f"{hours:02d}:{minutes:02d}:{seconds:02d}"
        return f"{minutes:02d}:{seconds:02d}"

def main():
    parser = argparse.ArgumentParser(description='Convert speech to text from audio file')
    parser.add_argument('audio_path', help='Path to audio file')
    parser.add_argument('--language', help='Language code (e.g., en, es, fr)')
    parser.add_argument('--model', default='base', help='Whisper model size (tiny, base, small, medium, large)')
    args = parser.parse_args()

    converter = SpeechToTextConverter(model_size=args.model)
    result = converter.convert(args.audio_path, args.language)
    print(json.dumps(result, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()
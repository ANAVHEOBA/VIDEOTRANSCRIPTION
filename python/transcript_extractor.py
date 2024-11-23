#!/usr/bin/env python3

import sys
import json
from typing import Dict, List, Optional
from youtube_transcript_api import YouTubeTranscriptApi
from youtube_transcript_api.formatters import TextFormatter
import argparse
from datetime import datetime

class TranscriptExtractor:
    def __init__(self):
        self.formatter = TextFormatter()

    def extract_transcript(self, video_id: str, languages: List[str] = ['en']) -> Dict:
        try:
            # Get transcript list
            transcript_list = YouTubeTranscriptApi.list_transcripts(video_id)
            
            # Try to get manual transcript first
            try:
                transcript = transcript_list.find_manually_created_transcript(languages)
            except:
                # Fall back to generated transcript
                try:
                    transcript = transcript_list.find_generated_transcript(languages)
                except:
                    # Try to get any available transcript and translate it
                    try:
                        transcript = transcript_list.find_transcript(transcript_list.translation_languages)
                        transcript = transcript.translate(languages[0])
                    except:
                        raise Exception("No transcript available")

            # Fetch transcript data
            transcript_data = transcript.fetch()

            return {
                'success': True,
                'video_id': video_id,
                'language': transcript.language_code,
                'is_generated': transcript.is_generated,
                'transcript': transcript_data,
                'metadata': {
                    'extracted_at': datetime.now().isoformat(),
                    'duration': self.calculate_duration(transcript_data),
                    'word_count': self.count_words(transcript_data),
                    'available_languages': [lang['language_code'] for lang in transcript_list.translation_languages],
                    'is_translatable': transcript.is_translatable
                }
            }

        except Exception as e:
            return {
                'success': False,
                'video_id': video_id,
                'error': str(e),
                'error_type': type(e).__name__
            }

    def calculate_duration(self, transcript_data: List[Dict]) -> float:
        if not transcript_data:
            return 0.0
        last_entry = transcript_data[-1]
        return round(last_entry['start'] + last_entry['duration'], 2)

    def count_words(self, transcript_data: List[Dict]) -> int:
        return sum(len(entry['text'].split()) for entry in transcript_data)

def main():
    parser = argparse.ArgumentParser(description='Extract YouTube video transcript')
    parser.add_argument('video_id', help='YouTube video ID')
    parser.add_argument('--languages', nargs='+', default=['en'], 
                        help='Preferred languages (e.g., en es fr)')
    args = parser.parse_args()

    extractor = TranscriptExtractor()
    result = extractor.extract_transcript(args.video_id, args.languages)
    print(json.dumps(result, ensure_ascii=False, indent=2))

if __name__ == "__main__":
    main()
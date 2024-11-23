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
            
            transcript = None
            error_messages = []

            # Try different methods to get transcript
            for language in languages:
                try:
                    # Try to get manual transcript
                    transcript = transcript_list.find_manually_created_transcript([language])
                    break
                except Exception as e:
                    error_messages.append(f"Manual transcript not found for {language}: {str(e)}")
                    try:
                        # Try to get generated transcript
                        transcript = transcript_list.find_generated_transcript([language])
                        break
                    except Exception as e:
                        error_messages.append(f"Generated transcript not found for {language}: {str(e)}")
                        continue

            # If no transcript found in preferred languages, try to get any available transcript
            if transcript is None:
                try:
                    available_transcripts = list(transcript_list._manually_created_transcripts.values())
                    available_transcripts.extend(list(transcript_list._generated_transcripts.values()))
                    
                    if available_transcripts:
                        transcript = available_transcripts[0]
                        # Try to translate if needed
                        if languages and transcript.language_code not in languages:
                            try:
                                transcript = transcript.translate(languages[0])
                            except Exception as e:
                                error_messages.append(f"Translation failed: {str(e)}")
                except Exception as e:
                    error_messages.append(f"Fallback transcript failed: {str(e)}")

            if transcript is None:
                raise Exception(f"No transcript available. Errors: {'; '.join(error_messages)}")

            # Fetch transcript data
            transcript_data = transcript.fetch()

            # Get available languages
            available_languages = []
            try:
                # Get manual transcripts
                available_languages.extend([t.language_code for t in transcript_list._manually_created_transcripts.values()])
                # Get generated transcripts
                available_languages.extend([t.language_code for t in transcript_list._generated_transcripts.values()])
                # Remove duplicates
                available_languages = list(set(available_languages))
            except Exception as e:
                error_messages.append(f"Failed to get available languages: {str(e)}")

            return {
                'success': True,
                'video_id': video_id,
                'language': transcript.language_code,
                'is_generated': getattr(transcript, 'is_generated', True),
                'transcript': transcript_data,
                'metadata': {
                    'extracted_at': datetime.now().isoformat(),
                    'duration': self.calculate_duration(transcript_data),
                    'word_count': self.count_words(transcript_data),
                    'available_languages': available_languages,
                    'is_translatable': getattr(transcript, 'is_translatable', False),
                    'errors': error_messages if error_messages else None
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
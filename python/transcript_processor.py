#!/usr/bin/env python3

import sys
import json
import argparse
from typing import Dict, List, Optional
from datetime import datetime
from youtube_transcript_api import YouTubeTranscriptApi
from youtube_transcript_api.formatters import TextFormatter

class TranscriptProcessor:
    def __init__(self):
        self.formatter = TextFormatter()

    def process_youtube_transcript(self, video_id: str, languages: List[str] = ['en']) -> Dict:
        """
        Extract and process transcript from YouTube video
        """
        try:
            # Get transcript list
            transcript_list = YouTubeTranscriptApi.list_transcripts(video_id)
            
            transcript = None
            error_messages = []

            # Try different methods to get transcript
            for language in languages:
                try:
                    # Try to get manual transcript first
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

            # Process and format transcript data
            formatted_transcript = self.format_transcript_data(transcript_data)

            return {
                'success': True,
                'video_id': video_id,
                'language': transcript.language_code,
                'is_generated': getattr(transcript, 'is_generated', True),
                'transcript': formatted_transcript,
                'raw_transcript': transcript_data,  # Include raw data for subtitle generation
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

    def format_transcript_data(self, transcript_data: List[Dict]) -> List[Dict]:
        """
        Format transcript data for subtitle generation
        """
        formatted_data = []
        for segment in transcript_data:
            formatted_data.append({
                'text': segment['text'].strip(),
                'start': segment['start'],
                'duration': segment['duration'],
                'start_time': self.format_timestamp(segment['start']),
                'end_time': self.format_timestamp(segment['start'] + segment['duration'])
            })
        return formatted_data

    def generate_srt_content(self, transcript_data: List[Dict]) -> str:
        """
        Generate SRT format subtitle content
        """
        srt_content = []
        for index, segment in enumerate(transcript_data, 1):
            srt_content.extend([
                str(index),
                f"{self.format_timestamp(segment['start'],True)} --> {self.format_timestamp(segment['start'] + segment['duration'],True)}",
                segment['text'].strip(),
                ""  # Empty line between entries
            ])
        return "\n".join(srt_content)

    def format_timestamp(self, seconds: float, srt_format: bool = False) -> str:
        """
        Format time in seconds to timestamp
        """
        hours = int(seconds // 3600)
        minutes = int((seconds % 3600) // 60)
        secs = seconds % 60
        
        if srt_format:
            # Format: 00:00:00,000
            return f"{hours:02d}:{minutes:02d}:{int(secs):02d},{int((secs % 1) * 1000):03d}"
        else:
            # Format: 00:00:00
            return f"{hours:02d}:{minutes:02d}:{secs:06.3f}"

    def calculate_duration(self, transcript_data: List[Dict]) -> float:
        """
        Calculate total duration of the transcript
        """
        if not transcript_data:
            return 0.0
        last_entry = transcript_data[-1]
        return round(last_entry['start'] + last_entry['duration'], 2)

    def count_words(self, transcript_data: List[Dict]) -> int:
        """
        Count total words in transcript
        """
        return sum(len(entry['text'].split()) for entry in transcript_data)

    def save_srt_file(self, transcript_data: List[Dict], output_path: str) -> bool:
        """
        Save transcript as SRT file
        """
        try:
            srt_content = self.generate_srt_content(transcript_data)
            with open(output_path, 'w', encoding='utf-8') as f:
                f.write(srt_content)
            return True
        except Exception as e:
            print(f"Error saving SRT file: {str(e)}", file=sys.stderr)
            return False

def main():
    parser = argparse.ArgumentParser(description='Process video transcripts')
    parser.add_argument('video_id', help='YouTube video ID')
    parser.add_argument('--languages', nargs='+', default=['en'], 
                        help='Preferred languages (e.g., en es fr)')
    parser.add_argument('--output', help='Output SRT file path')
    parser.add_argument('--format', choices=['json', 'srt'], default='json',
                        help='Output format (default: json)')
    args = parser.parse_args()

    processor = TranscriptProcessor()
    result = processor.process_youtube_transcript(args.video_id, args.languages)

    if result['success']:
        if args.format == 'srt':
            if args.output:
                if processor.save_srt_file(result['raw_transcript'], args.output):
                    print(f"SRT file saved to: {args.output}")
                else:
                    sys.exit(1)
            else:
                print(processor.generate_srt_content(result['raw_transcript']))
        else:
            print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        print(json.dumps(result, ensure_ascii=False, indent=2), file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
*/


import React, { useState, useEffect } from 'react';
import { X, FileJson, Upload, Copy, Check } from 'lucide-react';

interface JsonModalProps {
  isOpen: boolean;
  onClose: () => void;
  data?: string;
  isImport?: boolean;
  onImport?: (json: string) => void;
}

export const JsonModal: React.FC<JsonModalProps> = ({ isOpen, onClose, data = '', isImport = false, onImport }) => {
  const [importText, setImportText] = useState('');
  const [error, setError] = useState('');
  const [isCopied, setIsCopied] = useState(false);

  useEffect(() => {
      if (isOpen) {
          setImportText('');
          setError('');
          setIsCopied(false);
      }
  }, [isOpen]);

  if (!isOpen) return null;

  const handleImportClick = () => {
      if (!importText.trim()) {
          setError('Please paste JSON data first.');
          return;
      }
      try {
          JSON.parse(importText); // Simple validation
          if (onImport) {
              onImport(importText);
              onClose();
          }
      } catch (e) {
          setError('Invalid JSON format. Please check your input.');
      }
  };

  const handleCopy = async () => {
      if (!data) return;
      try {
          await navigator.clipboard.writeText(data);
          setIsCopied(true);
          setTimeout(() => setIsCopied(false), 2000);
      } catch (err) {
          console.error('Failed to copy:', err);
      }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/20 backdrop-blur-md p-4 font-sans">
      <div className="bg-white rounded-3xl shadow-2xl w-full max-w-2xl flex flex-col h-[70vh] border-4 border-slate-100 animate-in fade-in zoom-in duration-200 scale-95 sm:scale-100">
        
        <div className="flex items-center justify-between p-6 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <div className={`p-2 rounded-xl ${isImport ? 'bg-emerald-100 text-emerald-600' : 'bg-blue-100 text-blue-600'}`}>
                {isImport ? <Upload size={24} strokeWidth={2.5} /> : <FileJson size={24} strokeWidth={2.5} />}
            </div>
            <div>
                <h2 className="text-xl font-extrabold text-slate-800">
                    {isImport ? 'Import Blueprint' : 'Copy and share your model'}
                </h2>
                <p className="text-xs font-bold text-slate-400 uppercase tracking-wide">JSON Format</p>
            </div>
          </div>
          <button 
            onClick={onClose}
            className="p-2 rounded-xl bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-colors"
          >
            <X size={24} strokeWidth={3} />
          </button>
        </div>

        <div className="flex-1 p-6 overflow-hidden bg-slate-50/50 flex flex-col relative">
          <textarea 
            readOnly={!isImport}
            value={isImport ? importText : data}
            onChange={isImport ? (e) => setImportText(e.target.value) : undefined}
            placeholder={isImport ? "Paste your voxel JSON data here..." : ""}
            className={`w-full h-full resize-none bg-white border-2 rounded-xl p-4 font-mono text-xs text-slate-600 focus:outline-none transition-all ${isImport ? 'border-emerald-200 focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100' : 'border-slate-200 focus:border-blue-400 focus:ring-4 focus:ring-blue-100'}`}
          />
          
          {isImport && error && (
              <div className="absolute bottom-8 left-8 right-8 bg-rose-100 text-rose-600 px-4 py-2 rounded-lg text-xs font-bold shadow-sm border border-rose-200 animate-in slide-in-from-bottom-2">
                  {error}
              </div>
          )}
        </div>

        <div className="p-6 border-t border-slate-100 flex justify-end bg-white rounded-b-3xl gap-3">
          {isImport ? (
              <>
                <button 
                    onClick={onClose}
                    className="px-6 py-3 text-slate-500 font-bold hover:bg-slate-50 rounded-xl transition-colors"
                >
                    Cancel
                </button>
                <button 
                    onClick={handleImportClick}
                    className="px-6 py-3 bg-emerald-500 text-white text-sm font-bold rounded-xl hover:bg-emerald-600 transition-colors shadow-lg shadow-emerald-500/30 border-b-[4px] border-emerald-700 active:border-b-0 active:translate-y-[4px]"
                >
                    Import Build
                </button>
              </>
          ) : (
              <>
                <button
                    onClick={handleCopy}
                    className={`
                        flex items-center gap-2 px-6 py-3 text-sm font-bold rounded-xl transition-all border-b-[4px] active:border-b-0 active:translate-y-[4px]
                        ${isCopied 
                            ? 'bg-emerald-500 text-white border-emerald-700 shadow-lg shadow-emerald-500/30' 
                            : 'bg-blue-500 text-white border-blue-700 shadow-lg shadow-blue-500/30 hover:bg-blue-600'}
                    `}
                >
                    {isCopied ? <Check size={18} strokeWidth={3} /> : <Copy size={18} strokeWidth={2.5} />}
                    {isCopied ? 'Copied!' : 'Copy All'}
                </button>
                <button 
                    onClick={onClose}
                    className="px-6 py-3 bg-slate-800 text-white text-sm font-bold rounded-xl hover:bg-slate-900 transition-colors shadow-lg shadow-slate-900/20"
                >
                    Close
                </button>
              </>
          )}
        </div>

      </div>
    </div>
  );
};

import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { CheckIcon, CopyIcon } from 'lucide-react';
import { useState } from 'react';

interface CopyButtonProps {
    value: string;
    className?: string;
    size?: 'default' | 'sm' | 'lg' | 'icon';
}

export function CopyButton({ value, className, size = 'icon' }: CopyButtonProps) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard API not available
            console.error('Failed to copy to clipboard');
        }
    };

    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button variant="ghost" size={size} onClick={handleCopy} className={cn('h-8 w-8', className)}>
                        {copied ? <CheckIcon className="h-4 w-4 text-green-500" /> : <CopyIcon className="h-4 w-4" />}
                        <span className="sr-only">Copy to clipboard</span>
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    <p>{copied ? 'Copied!' : 'Copy to clipboard'}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

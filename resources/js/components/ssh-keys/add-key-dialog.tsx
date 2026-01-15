import { store } from '@/actions/App/Http/Controllers/SshKeyController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { KeyIcon, PlusIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface AddKeyDialogProps {
    trigger?: ReactNode;
}

export function AddKeyDialog({ trigger }: AddKeyDialogProps) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        public_key: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(store.url(), {
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button>
                        <PlusIcon className="mr-2 h-4 w-4" />
                        Add SSH Key
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <KeyIcon className="h-5 w-5" />
                        Add SSH Key
                    </DialogTitle>
                    <DialogDescription>Add a public SSH key to sync to your servers for secure access.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            placeholder="My MacBook"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            disabled={processing}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="public_key">Public Key</Label>
                        <Textarea
                            id="public_key"
                            placeholder="ssh-ed25519 AAAA... or ssh-rsa AAAA..."
                            value={data.public_key}
                            onChange={(e) => setData('public_key', e.target.value)}
                            disabled={processing}
                            rows={5}
                            className="font-mono text-xs"
                        />
                        <InputError message={errors.public_key} />
                        <p className="text-muted-foreground text-xs">
                            Paste your public key (id_ed25519.pub or id_rsa.pub). It should start with ssh-ed25519 or ssh-rsa.
                        </p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Adding...' : 'Add Key'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

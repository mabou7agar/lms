"use client";

import { ChevronDown } from "lucide-react";
import {
  createContext,
  useContext,
  useId,
  useState,
  type HTMLAttributes,
  type ReactNode,
} from "react";
import { cn } from "@/lib/utils";

interface AccordionContextValue {
  isOpen: (value: string) => boolean;
  toggle: (value: string) => void;
}
const AccordionContext = createContext<AccordionContextValue | null>(null);

interface ItemContextValue {
  value: string;
  triggerId: string;
  contentId: string;
}
const ItemContext = createContext<ItemContextValue | null>(null);

export interface AccordionProps extends Omit<HTMLAttributes<HTMLDivElement>, "defaultValue"> {
  type?: "single" | "multiple";
  collapsible?: boolean;
  defaultValue?: string | string[];
}

export function Accordion({ type = "single", collapsible = true, defaultValue, className, children, ...props }: AccordionProps) {
  const [open, setOpen] = useState<string[]>(
    defaultValue ? (Array.isArray(defaultValue) ? defaultValue : [defaultValue]) : [],
  );

  const toggle = (value: string) => {
    setOpen((prev) => {
      const has = prev.includes(value);
      if (type === "single") {
        if (has) return collapsible ? [] : prev;
        return [value];
      }
      return has ? prev.filter((v) => v !== value) : [...prev, value];
    });
  };

  return (
    <AccordionContext.Provider value={{ isOpen: (v) => open.includes(v), toggle }}>
      <div className={cn("divide-y divide-border", className)} {...props}>
        {children}
      </div>
    </AccordionContext.Provider>
  );
}

export function AccordionItem({ value, className, children, ...props }: { value: string } & HTMLAttributes<HTMLDivElement>) {
  const uid = useId();
  return (
    <ItemContext.Provider value={{ value, triggerId: `${uid}-trigger`, contentId: `${uid}-content` }}>
      <div className={cn(className)} {...props}>
        {children}
      </div>
    </ItemContext.Provider>
  );
}

export function AccordionTrigger({ className, children, ...props }: HTMLAttributes<HTMLButtonElement>) {
  const acc = useContext(AccordionContext);
  const item = useContext(ItemContext);
  if (!acc || !item) throw new Error("AccordionTrigger must be used within <AccordionItem>");
  const open = acc.isOpen(item.value);
  return (
    <button
      type="button"
      id={item.triggerId}
      aria-expanded={open}
      aria-controls={item.contentId}
      data-state={open ? "open" : "closed"}
      onClick={() => acc.toggle(item.value)}
      className={cn(
        "flex w-full items-center justify-between gap-2 py-4 text-start text-sm font-medium transition-colors duration-[--duration-fast] hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        className,
      )}
      {...props}
    >
      {children}
      <ChevronDown
        aria-hidden
        className={cn("size-4 shrink-0 text-muted-foreground transition-transform duration-[--duration-normal]", open && "rotate-180")}
      />
    </button>
  );
}

export function AccordionContent({ className, children, ...props }: HTMLAttributes<HTMLDivElement>) {
  const acc = useContext(AccordionContext);
  const item = useContext(ItemContext);
  if (!acc || !item) throw new Error("AccordionContent must be used within <AccordionItem>");
  const open = acc.isOpen(item.value);
  return (
    <div
      id={item.contentId}
      role="region"
      aria-labelledby={item.triggerId}
      hidden={!open}
      className={cn(open && "motion-expand", "overflow-hidden pb-4 text-sm text-muted-foreground", className)}
      {...props}
    >
      {children}
    </div>
  );
}

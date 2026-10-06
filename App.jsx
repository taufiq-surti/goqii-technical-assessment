import React, {createContext, useContext, useEffect, useRef, useState} from 'react';
import {createRoot} from 'react-dom/client';
import {BrowserRouter, Navigate, Route, Routes, useNavigate} from 'react-router-dom';

const Auth=createContext(null), statuses=['todo','in-progress','done'], priorities=['low','medium','high'];
const empty={title:'',description:'',status:'todo',priority:'medium',due_date:''};
const label=s=>s.replace(/[-_]/g,' ');
async function request(path,{token,body,method='GET',signal}={}) {
  const res=await fetch(`/api${path}`,{method,signal,headers:{...(token?{Authorization:`Bearer ${token}`}:{ }),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});
  const data=res.status===204?null:await res.json().catch(()=>({error:res.status===429?'Too many requests; try again shortly.':'Unexpected server response'}));
  if(!res.ok) throw Object.assign(new Error(data?.error||'Request failed'),{status:res.status});
  return data;
}
function Protected({children}) { return useContext(Auth).session?children:<Navigate to="/login" replace/>; }
function Login() {
  const {session,setSession}=useContext(Auth), [error,setError]=useState(''), [busy,setBusy]=useState(false), [mode,setMode]=useState('login'), navigate=useNavigate();
  if(session) return <Navigate to="/tasks" replace/>;
  async function submit(e) {
    e.preventDefault(); const form=new FormData(e.currentTarget); const payload=Object.fromEntries(form); setBusy(true); setError('');
    try {
      const endpoint = mode === 'register' ? '/register' : '/login';
      const result = await request(endpoint,{method:'POST',body:payload});
      setSession(result); navigate('/tasks',{replace:true});
    }
    catch(e) {setError(e.message);} finally {setBusy(false);}
  }
  return <main><h1>GOQii Tasks</h1><div style={{display:'flex',gap:'8px',marginBottom:'16px'}}><button type="button" onClick={()=>setMode('login')} disabled={mode==='login'}>Login</button><button type="button" onClick={()=>setMode('register')} disabled={mode==='register'}>Register</button></div><form onSubmit={submit}><label>Email<input name="email" type="email" autoComplete="username" maxLength={254} required/></label><label>Password<input name="password" type="password" autoComplete={mode==='register'?'new-password':'current-password'} minLength={12} maxLength={72} required/></label><button disabled={busy}>{busy ? (mode==='register'?'Creating account…':'Signing in…') : (mode==='register'?'Create account':'Sign in')}</button></form><p role="alert">{error}</p></main>;
}
function Tasks() {
  const {session,setSession}=useContext(Auth), [data,setData]=useState({items:[],total:0}), [status,setStatus]=useState(''), [page,setPage]=useState(1), [revision,setRevision]=useState(0), [draft,setDraft]=useState(empty), [editing,setEditing]=useState(null), [error,setError]=useState(''), [loading,setLoading]=useState(true), [busy,setBusy]=useState(false), sequence=useRef(0);
  const limit=10, pages=Math.max(1,Math.ceil(data.total/limit));
  const [suggestion,setSuggestion]=useState(null), [suggesting,setSuggesting]=useState(false);
  useEffect(()=>setSuggestion(null),[draft.title,draft.description,editing]);
  useEffect(()=>{
    try {
      const payload=JSON.parse(atob(session.token.split('.')[1].replace(/-/g,'+').replace(/_/g,'/')));
      if (!Number.isFinite(payload.exp)) throw new Error('Missing expiry');
      console.log('Token expires:',new Date(payload.exp*1000).toLocaleString());
      console.log('Seconds remaining:',Math.max(0,payload.exp-Math.floor(Date.now()/1000)));
    } catch {
      console.warn('Unable to read token expiry.');
    }
  },[session.token]);
  function failure(e) { if(e.status===401) setSession(null); else if(e.name!=='AbortError') setError(e.message); }
  useEffect(()=>{
    const controller=new AbortController(), current=++sequence.current;
    setLoading(true); setError('');
    request(`/tasks?page=${page}&limit=${limit}${status?`&status=${status}`:''}`,{token:session.token,signal:controller.signal})
      .then(result=>{if(current===sequence.current){setData(result); const last=Math.max(1,Math.ceil(result.total/limit)); if(page>last)setPage(last);}})
      .catch(e=>{if(current===sequence.current) failure(e);})
      .finally(()=>{if(current===sequence.current&&!controller.signal.aborted)setLoading(false);});
    return ()=>controller.abort();
  },[page,status,revision,session.token]);
  async function mutate(path,method,body) {
    setBusy(true); setError('');
    try { await request(path,{method,body,token:session.token}); setRevision(n=>n+1); return true; }
    catch(e) {failure(e); return false;} finally {setBusy(false);}
  }
  async function save(e) { e.preventDefault(); if(await mutate(editing?`/tasks/${editing}`:'/tasks',editing?'PUT':'POST',draft)){setDraft(empty);setEditing(null);} }
  async function suggest() {
    setBusy(true); setSuggesting(true); setSuggestion(null); setError('');
    try { setSuggestion(await request('/suggest-category',{method:'POST',token:session.token,body:{title:draft.title,description:draft.description}})); }
    catch(e) {failure(e);} finally {setBusy(false);setSuggesting(false);}
  }
  async function remove(task) { if(window.confirm(`Delete “${task.title}”?`)&&await mutate(`/tasks/${task.id}`,'DELETE')&&editing===task.id){setDraft(empty);setEditing(null);} }
  async function logout() {setBusy(true);try {await request('/logout',{method:'POST',token:session.token});setSession(null);}catch(e){failure(e);}finally{setBusy(false);}}
  return <main><header><h1>GOQii Tasks</h1><span>{session.user.email} · {session.user.role}</span><button onClick={logout} disabled={busy}>Sign out</button></header>
    <p>{session.user.role==='Admin'?'Manage all users’ tasks. New tasks belong to you.':'Manage your tasks.'}</p>
    <form onSubmit={save}><fieldset disabled={busy}><legend>{editing?'Edit task':'Create task'}</legend>
      <label>Title<input value={draft.title} maxLength={200} required onChange={e=>setDraft({...draft,title:e.target.value})}/></label>
      <label>Description<textarea value={draft.description} maxLength={5000} onChange={e=>setDraft({...draft,description:e.target.value})}/></label>
      <label>Status<select value={draft.status} onChange={e=>setDraft({...draft,status:e.target.value})}>{statuses.map(s=><option key={s} value={s}>{label(s)}</option>)}</select></label>
      <label>Priority<select value={draft.priority} onChange={e=>setDraft({...draft,priority:e.target.value})}>{priorities.map(p=><option key={p} value={p}>{p}</option>)}</select></label>
      <label>Due date<input type="date" value={draft.due_date||''} onChange={e=>setDraft({...draft,due_date:e.target.value})}/></label>
      <p id="ai-disclosure">AI suggestions are informational only. The task model does not require a category field.</p>
      <button type="button" disabled={!draft.title.trim()} aria-describedby="ai-disclosure" onClick={suggest}>{suggesting?'Suggesting…':'Suggest category with AI'}</button>
      {suggestion&&<div role="status"><span>Suggested category: {suggestion.category} · Confidence: {Math.round(suggestion.confidence*100)}%{suggestion.fallback?' · Low confidence; using Other.':''}</span></div>}
      <button>{busy?'Working…':editing?'Save changes':'Create task'}</button>{editing&&<button type="button" onClick={()=>{setEditing(null);setDraft(empty);}}>Cancel</button>}
    </fieldset></form>
    <p role="alert">{error}</p><label>Filter status<select value={status} disabled={busy} onChange={e=>{setStatus(e.target.value);setPage(1);}}><option value="">All</option>{statuses.map(s=><option key={s} value={s}>{label(s)}</option>)}</select></label>
    <section aria-busy={loading} aria-label="Tasks">{loading?<p role="status">Loading…</p>:data.items.length?data.items.map(task=><article key={task.id}><h2>{task.title}</h2><p className="description">{task.description}</p><p>{label(task.status)} · {task.priority} · {task.due_date ? new Date(task.due_date+'T00:00:00').toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'}) : 'No due date'}{session.user.role==='Admin'?` · Owner #${task.user_id}`:''}</p><button disabled={busy} onClick={()=>{setEditing(task.id);setDraft({title:task.title,description:task.description,status:task.status,priority:task.priority||'medium',due_date:task.due_date||''});}}>Edit</button><button disabled={busy} onClick={()=>remove(task)}>Delete</button></article>):<p>No tasks found.</p>}</section>
    <nav aria-label="Pagination"><button disabled={loading||busy||page===1} onClick={()=>setPage(p=>p-1)}>Previous</button><span>Page {page} of {pages} · {data.total} tasks</span><button disabled={loading||busy||page>=pages||page>=10000} onClick={()=>setPage(p=>p+1)}>Next</button></nav>
  </main>;
}
export default function App() {
  const [session,setSession]=useState(null);
  return <Auth.Provider value={{session,setSession}}><BrowserRouter><Routes><Route path="/login" element={<Login/>}/><Route path="/tasks" element={<Protected><Tasks/></Protected>}/><Route path="*" element={<Navigate to="/tasks" replace/>}/></Routes></BrowserRouter></Auth.Provider>;
}
const style=document.createElement('style');
style.textContent='*{box-sizing:border-box}body{margin:0;background:#f4f6fa;color:#172638;font:16px system-ui}main{max-width:850px;margin:32px auto;padding:24px}header,nav{display:flex;align-items:center;gap:16px;flex-wrap:wrap}h1{margin-right:auto}form,article{padding:20px;background:white;border:1px solid #cad2df;border-radius:10px;margin:18px 0}fieldset{border:0;padding:0}legend{font-weight:700}label{display:block;margin:12px 0}input,textarea,select{display:block;width:100%;padding:10px;margin-top:5px;font:inherit}textarea{min-height:80px}button{padding:10px 16px;margin:4px;border:1px solid #166448;border-radius:6px;background:#166448;color:white;cursor:pointer;font:inherit}button:disabled{opacity:.5;cursor:default}[role=alert]{color:#a41522}.description{white-space:pre-wrap;overflow-wrap:anywhere}h2{overflow-wrap:anywhere}:focus-visible{outline:3px solid #277ade;outline-offset:2px}';
document.head.appendChild(style);
createRoot(document.getElementById('root')).render(<App/>);

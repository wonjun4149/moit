# main_V3.py (main_tea.py 기반 + StateGraph 취미 추천 에이전트 이식)

# --- 1. 기본 라이브러리 import ---
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import os
from dotenv import load_dotenv
import requests
import json
from datetime import datetime # 오늘 날짜 확인을 위해 추가
from typing import List, TypedDict, Optional
import logging
from fastapi.middleware.cors import CORSMiddleware

# --- 2. 로깅 기본 설정 ---
logging.basicConfig(level=logging.INFO, format='%(levelname)s:     %(message)s')

# --- 3. LangChain, LangGraph 및 AI 관련 라이브러리 import ---
from langchain_openai import ChatOpenAI, OpenAIEmbeddings
from langchain_core.prompts import ChatPromptTemplate, MessagesPlaceholder
from langchain_core.output_parsers import StrOutputParser
from langchain_core.runnables import chain
from langchain_pinecone import PineconeVectorStore # ReAct Agent
from langgraph.prebuilt import create_react_agent
from langchain.agents import AgentExecutor, create_openai_tools_agent
from langgraph.graph import StateGraph, END
from langchain_core.tools import tool
from tenacity import retry, stop_after_attempt, wait_fixed
import google.generativeai as genai # Gemini 추가
from requests.exceptions import Timeout, ConnectionError
from langchain_community.tools.tavily_search import TavilySearchResults
from langchain.tools.retriever import create_retriever_tool
from langchain_core.documents import Document

# --- 4. 환경 설정 및 FastAPI 앱 초기화 ---
load_dotenv()
app = FastAPI(
    title="MOIT AI Agent Server v3 (StateGraph 이식)",
    description="main_tea.py 기반 위에 StateGraph 취미 추천 에이전트를 이식한 버전",
    version="3.0.0",
)

# --- CORS 미들웨어 추가 ---
origins = ["*"]
app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- AI 모델 및 API 키 설정 ---
try:
    gemini_api_key = os.getenv("GOOGLE_API_KEY")
    if gemini_api_key:
        genai.configure(api_key=gemini_api_key)
    else:
        logging.warning("GOOGLE_API_KEY가 .env 파일에 설정되지 않았습니다. 사진 분석 기능이 작동하지 않을 수 있습니다.")
except Exception as e:
    logging.warning(f"Gemini API 키 설정 실패: {e}")

llm = ChatOpenAI(model="gpt-4o-mini")


# --- 5. 마스터 에이전트 로직 전체 정의 ---

# 5-1. 마스터 에이전트의 State 정의
class MasterAgentState(TypedDict):
    user_input: dict
    route: str
    final_answer: str

# 5-2. 라우터 노드 정의
router_prompt = ChatPromptTemplate.from_template(
    """당신은 사용자의 요청을 분석하여 어떤 담당자에게 전달해야 할지 결정하는 AI 라우터입니다.
    사용자의 요청을 보고, 아래 세 가지 경로 중 가장 적절한 경로 하나만 골라 그 이름만 정확히 답변해주세요.
 
    [경로 설명]
    - `meeting_matching`: 사용자가 '새로운 모임'을 만들려고 할 때, 기존에 있던 '유사한 모임'을 추천해주는 경로입니다. 입력에 'title', 'description' 키가 포함되어 있으면 이 경로일 확률이 높습니다.
    - `hobby_recommendation`: 사용자에게 '새로운 취미' 자체를 추천해주는 경로입니다. 입력에 'survey' 키가 포함되어 있으면 이 경로일 확률이 매우 높습니다.
    - `general_search`: 사용자가 날씨, 맛집, 특정 정보 검색 등 '일반적인 질문'을 하거나, '모임/취미 추천' 이외의 대화를 시도할 때 사용되는 경로입니다. 예를 들어 "주말에 비 오는데 뭐하지?" 와 같은 질문이 해당됩니다.
 
    [사용자 요청]:
    {user_input}
 
    [판단 결과 (meeting_matching, hobby_recommendation, 또는 general_search)]:
    """
)
router_chain = router_prompt | llm | StrOutputParser()

def route_request(state: MasterAgentState):
    """사용자의 입력을 보고 어떤 전문가에게 보낼지 결정하는 노드"""
    logging.info("--- ROUTING ---")
    # [수정] 불필요한 로그 라인을 제거하고, invoke에 user_input을 직접 전달합니다.
    route_decision = router_chain.invoke({"user_input": state['user_input']})
    cleaned_decision = route_decision.strip().lower().replace("'", "").replace('"', '')
    logging.info(f"라우팅 결정: {cleaned_decision}")
    return {"route": cleaned_decision}

# 5-3. 전문가 호출 노드들 정의

# --- 범용 검색 에이전트에서 사용할 도구 ---
@tool
def get_current_date() -> str:
    """오늘 날짜를 'YYYY년 MM월 DD일' 형식의 문자열로 반환합니다. '오늘', '내일', '이번 주'와 같은 상대적인 시간 표현을 정확히 해석해야 할 때 사용하세요."""
    return datetime.now().strftime("%Y년 %m월 %d일")


# 전문가 0: 범용 검색 에이전트 (신규 추가)
def call_general_search_agent(state: MasterAgentState):
    """'범용 검색 에이전트'를 호출하여 웹 검색 또는 내부 모임 DB 검색을 수행하는 노드"""
    logging.info("--- CALLING: General Search Agent ---")

    # 1. 도구 정의
    # 1-1. 웹 검색 도구
    tavily_tool = TavilySearchResults(max_results=3, name="web_search")
    tavily_tool.description = "날씨, 뉴스, 맛집, 특정 주제에 대한 최신 정보 등 외부 세계에 대한 질문에 답할 때 사용합니다."

    @retry(stop=stop_after_attempt(3), wait=wait_fixed(2))
    def web_search_with_retry(query: str):
        """날씨, 뉴스, 맛집, 특정 주제에 대한 최신 정보 등 외부 세계에 대한 질문에 답할 때 사용합니다."""
        try:
            return tavily_tool.invoke({"query": query})
        except (Timeout, ConnectionError) as e:
            logging.error(f"Web search failed due to connection error or timeout: {e}")
            raise  # 다시 시도하려면 예외를 다시 발생시킵니다.
        except Exception as e:
            logging.error(f"Web search failed with error: {e}")
            return f"오류: 웹 검색에 실패했습니다. {e}"  # 오류 메시지 반환
        except Exception as e:
            logging.error(f"Web search failed with error: {e}")
            raise  # Re-raise the exception to trigger retry


    web_search_with_retry.description = "날씨, 뉴스, 맛집, 특정 주제에 대한 최신 정보 등 외부 세계에 대한 질문에 답할 때 사용합니다."
    # 1-2. 내부 모임 DB 검색 도구 (기존 로직 재활용)
    meeting_index_name = os.getenv("PINECONE_INDEX_NAME_MEETING")
    if not meeting_index_name: raise ValueError("'.env' 파일에 PINECONE_INDEX_NAME_MEETING 변수를 설정해야 합니다.")
    embedding_function = OpenAIEmbeddings(model='text-embedding-3-large')
    vector_store = PineconeVectorStore.from_existing_index(index_name=meeting_index_name, embedding=embedding_function)
    retriever = vector_store.as_retriever(search_type="similarity", search_kwargs={'k': 3})
    
    moit_meeting_retriever_tool = create_retriever_tool(
        retriever,
        "moit_internal_meeting_search",
        "MOIT 서비스 내에 등록된 기존 모임 정보를 검색합니다. '실내 활동', '서울 지역 주말 모임' 등 사용자가 찾는 조건에 맞는 모임을 찾아 추천할 때 사용합니다."
    )

    # 1-3. 오늘 날짜 확인 도구
    get_current_date_tool = get_current_date
    tools = [web_search_with_retry, moit_meeting_retriever_tool]

    tools = [tavily_tool, moit_meeting_retriever_tool, get_current_date_tool]

    # 2. ReAct 에이전트 생성
    # [중요] ReAct 프롬프트에 에이전트의 역할과 도구 사용법을 명확히 지시합니다.
    react_prompt = ChatPromptTemplate.from_messages(

        [
            ("system", """당신은 사용자의 질문에 가장 유용한 답변을 제공하는 AI 어시스턴트 'MOIT'입니다.
            당신은 세 가지 도구를 사용할 수 있습니다: 'web_search', 'moit_internal_meeting_search', 'get_current_date'.

            [지침]
            1. **시간 인식**: 질문에 '오늘', '내일' 등 상대적 시간이 있으면, 먼저 `get_current_date`로 오늘 날짜를 확인하고, 그 다음 `web_search`로 가장 공신력있는 정보를 찾으세요. (예: 날씨는 네이버날씨(weather.naver.com)를 통해 검색하세요)
            2. **모임 검색**: "축구 하고 싶은데 모임 없나?"처럼 MOIT 서비스 내 모임을 찾는 요청에는 `moit_internal_meeting_search`를 사용하세요.
            3. **복합 질문 처리**: "비 오는 주말에 ?" 같은 질문에는, `web_search`로 날씨를 확인한 후, 그 결과를 이용해 `moit_internal_meeting_search`로 '실내 모임'을 찾거나 없다면 새로운 취미를 추천해주는 등 도구를 조합하여 최적의 답을 찾으세요.
            4. **일반 질문**: 그 외 모든 일반적인 질문(뉴스, 맛집, 상식, 추천)은 `web_search`를 사용하세요.
            
            최종 답변은 항상 친절한 말투로 정리하고, 모임을 추천할 때는 참여를 유도하는 문구를 포함해주세요.
            """),
            MessagesPlaceholder(variable_name="chat_history"),
            ("human", "{input}"), # "user_input" 대신 "input" 사용
            MessagesPlaceholder(variable_name="agent_scratchpad"),
            MessagesPlaceholder(variable_name="chat_history", optional=True),
            ("human", "{input}"),
            MessagesPlaceholder(variable_name="agent_scratchpad")
        ]
    )
    
    general_agent_runnable = create_react_agent(llm, tools, react_prompt)
    agent = create_openai_tools_agent(llm, tools, react_prompt)
    general_agent_runnable = AgentExecutor(agent=agent, tools=tools, verbose=True)

    # 3. 에이전트 실행
    # MasterAgent의 user_input 형식에 맞게 실제 질문을 추출합니다.
    # 예: {'messages': [['user', '주말에 비오는데 뭐하지?']]}
    try:
        user_question = state['user_input']['messages'][0][1]
    except (KeyError, IndexError):
        # 만약 위 구조가 아닐 경우, user_input 전체를 사용
        user_question = str(state['user_input'])

    input_data = {"input": user_question, "chat_history": []} # chat_history 추가
    logging.info(f"범용 검색 에이전트에게 전달된 질문: {user_question}") # 로깅 수정
    logging.info(f"general_agent_runnable.invoke에 전달되는 입력: {input_data}") # 로깅 추가

    result = general_agent_runnable.invoke(input_data) # input_data를 사용하여 호출
    logging.info(f"범용 검색 에이전트에게 전달된 질문: {user_question}")
    
    result = general_agent_runnable.invoke({"input": user_question})
    
    final_answer = result.get("output", "질문을 이해하지 못했습니다. 다시 질문해주세요.")
    logging.info(f"범용 검색 에이전트의 최종 답변: {final_answer}")
    
    return {"final_answer": final_answer}

# 전문가 1: 모임 매칭 에이전트 (SubGraph) - main_tea.py 코드 기반
def call_meeting_matching_agent(state: MasterAgentState):
    """'모임 매칭 에이전트'를 독립적인 SubGraph로 실행하고 결과를 받아오는 노드"""
    logging.info("--- CALLING: Meeting Matching Agent ---")

    class MeetingAgentState(TypedDict):
        title: str; description: str; time: str; location: str; query: str
        context: List[Document]; answer: str; decision: str; rewrite_count: int

    meeting_llm = ChatOpenAI(model="gpt-4o-mini")
    meeting_index_name = os.getenv("PINECONE_INDEX_NAME_MEETING")
    if not meeting_index_name: raise ValueError("'.env' 파일에 PINECONE_INDEX_NAME_MEETING 변수를 설정해야 합니다.")
    
    embedding_function = OpenAIEmbeddings(model='text-embedding-3-large')
    vector_store = PineconeVectorStore.from_existing_index(index_name=meeting_index_name, embedding=embedding_function)
    retriever = vector_store.as_retriever(
        search_type="similarity_score_threshold",
        search_kwargs={'score_threshold': 0.75, 'k': 2}
    )  

    prepare_query_prompt = ChatPromptTemplate.from_template(
        """당신은 사용자가 입력한 정보를 바탕으로 유사한 다른 정보를 검색하기 위한 최적의 검색어를 만드는 전문가입니다.
        아래 [모임 정보]를 종합하여, 벡터 데이터베이스에서 유사한 모임을 찾기 위한 가장 핵심적인 검색 질문을 한 문장으로 만들어주세요.
        [모임 정보]:
- 제목: {title}
- 설명: {description}
- 시간: {time}
- 장소: {location}"""
    )
    prepare_query_chain = prepare_query_prompt | meeting_llm | StrOutputParser()
    def prepare_query(m_state: MeetingAgentState):
        logging.info("--- (Sub) Preparing Query ---")
        query = prepare_query_chain.invoke({"title": m_state['title'], "description": m_state['description'], "time": m_state.get('time', ''), "location": m_state.get('location', '')})
        logging.info(f"생성된 검색어: {query}")
        return {"query": query}

    def retrieve(m_state: MeetingAgentState):
        logging.info("--- (Sub) Retrieving Context from DB ---")
        context = retriever.invoke(m_state["query"])
        logging.info(f"DB에서 {len(context)}개의 유사 문서를 찾았습니다.")
        return {"context": context}

    generate_prompt = ChatPromptTemplate.from_template(
        """당신은 사용자의 요청을 매우 엄격하게 분석하여 유사한 모임을 추천하는 MOIT 플랫폼의 AI입니다.
        사용자가 만들려는 모임과 **주제, 활동 내용이 명확하게 일치하는** 기존 모임만 추천해야 합니다.

        [사용자 입력 정보]:
        {query}

        [검색된 유사 모임 정보]:
        {context}

        [지시사항]:
        1. [검색된 유사 모임 정보]의 각 항목을 [사용자 입력 정보]와 비교하여, **정말로 관련성이 높다고 판단되는 모임만** 골라냅니다. (예: '축구' 모임을 찾는 사용자에게 '야구' 모임은 추천하지 않습니다.)
        2. 1번에서 골라낸 모임이 있다면, 해당 모임을 기반으로 사용자에게 제안할 추천사를 친절한 말투("~는 어떠세요?")로 작성합니다.
        3. 1번에서 골라낸 모임의 `meeting_id`와 `title`을 추출하여 `recommendations` 배열을 구성합니다. **추천할 모임이 하나뿐이라면, 배열에 하나만 포함합니다.**
        4. 최종 답변을 아래와 같은 JSON 형식으로만 제공해주세요. 다른 텍스트는 절대 포함하지 마세요.

        [JSON 출력 형식 예시]:
        // 추천할 모임이 1개일 경우
        {{
            "summary": "비슷한 축구 모임이 있는데, 참여해 보시는 건 어떠세요?",
            "recommendations": [
                {{ "meeting_id": "축구 모임 ID", "title": "같이 축구하실 분!" }}
            ]
        }}

        // 1번에서 골라낸 모임이 없을 경우 (추천할 만한 모임이 없는 경우)
        {{
            "summary": "",
            "recommendations": []
        }}
        """
    )
    generate_chain = generate_prompt | meeting_llm | StrOutputParser()
    def generate(m_state: MeetingAgentState):
        logging.info("--- (Sub) Generating Final Answer ---")
        context_str = ""
        for i, doc in enumerate(m_state['context']):
            metadata = doc.metadata or {}
            meeting_id = metadata.get('meeting_id', 'N/A')
            title = metadata.get('title', 'N/A')
            context_str += f"모임 {i+1}:\n  - meeting_id: {meeting_id}\n  - title: {title}\n  - content: {doc.page_content}\n\n"
        if not m_state['context']: context_str = "유사한 모임을 찾지 못했습니다."
        logging.info(f"--- (Sub) 최종 추천 생성을 위해 LLM에 전달할 컨텍스트 ---\n{context_str}")
        answer = generate_chain.invoke({"context": context_str, "query": m_state['query']})
        return {"answer": answer}

    check_helpfulness_prompt = ChatPromptTemplate.from_template(
        """당신은 AI 답변을 평가하는 엄격한 평가관입니다. 주어진 [AI 답변]이 사용자의 [원본 질문] 의도에 대해 유용한 제안을 하는지 평가해주세요.
        다른 설명은 일절 추가하지 말고, 오직 'helpful' 또는 'unhelpful' 둘 중 하나의 단어로만 답변해야 합니다.

        [원본 질문]: {query}
        [AI 답변]: {answer}
        
        [평가 결과 (helpful 또는 unhelpful)]:"""
    )
    check_helpfulness_chain = check_helpfulness_prompt | meeting_llm | StrOutputParser()
    def check_helpfulness(m_state: MeetingAgentState):
        logging.info("--- (Sub) Checking Helpfulness ---")
        raw_result = check_helpfulness_chain.invoke({"query": m_state['query'], "answer": m_state['answer']})
        
        cleaned_result = raw_result.strip().lower().replace('"', '').replace("'", "")
        decision = "helpful" if cleaned_result == "helpful" else "unhelpful"
        
        logging.info(f"답변 유용성 평가 (Raw): {raw_result} -> (Parsed): {decision}")
        return {"decision": decision}

    rewrite_query_prompt = ChatPromptTemplate.from_template(
        """당신은 더 나은 검색 결과를 위해 질문을 재구성하는 프롬프트 엔지니어입니다.
        [원본 질문]은 벡터 검색에서 좋은 결과를 얻지 못했습니다. 원본 질문의 핵심 의도는 유지하되, 완전히 다른 관점에서 접근하거나, 더 구체적인 키워드를 사용하여 관련성 높은 모임을 찾을 수 있는 새로운 검색 질문을 하나만 만들어주세요.
        [원본 질문]: {query}
        [새로운 검색 질문]:"""
    )
    rewrite_query_chain = rewrite_query_prompt | meeting_llm | StrOutputParser()
    def rewrite_query(m_state: MeetingAgentState):
        logging.info("--- (Sub) Rewriting Query ---")
        new_query = rewrite_query_chain.invoke({"query": m_state['query']})
        logging.info(f"재작성된 검색어: {new_query}")
        count = m_state.get('rewrite_count', 0) + 1
        return {"query": new_query, "rewrite_count": count}

    def decide_to_continue(state: MeetingAgentState):
        if state.get("rewrite_count", 0) >= 2: 
            logging.info(f"--- 재시도 횟수({state.get('rewrite_count', 0)}) 초과했기에 루프를 종료합니다. ---")
            return END
        if state.get("decision") == "helpful":
            return END
        return "rewrite_query"

    graph_builder = StateGraph(MeetingAgentState)
    graph_builder.add_node("prepare_query", prepare_query)
    graph_builder.add_node("retrieve", retrieve)
    graph_builder.add_node("generate", generate)
    graph_builder.add_node("check_helpfulness", check_helpfulness)
    graph_builder.add_node("rewrite_query", rewrite_query)
    graph_builder.set_entry_point("prepare_query")
    graph_builder.add_edge("prepare_query", "retrieve")
    graph_builder.add_edge("retrieve", "generate")
    graph_builder.add_edge("generate", "check_helpfulness")
    graph_builder.add_conditional_edges("check_helpfulness", decide_to_continue)
    graph_builder.add_edge("rewrite_query", "retrieve")
    meeting_agent = graph_builder.compile()

    # create_meeting.php에서 오는 데이터 형식에 맞게 실제 데이터를 추출합니다.
    user_input = state['user_input'].get('messages', [[]])[0][1] if 'messages' in state['user_input'] else state['user_input']
    initial_state = {
        "title": user_input.get("title", ""),
        "description": user_input.get("description", ""),
        "time": user_input.get("time", ""),
        "location": user_input.get("location", ""),
        "rewrite_count": 0
    }
    
    final_result_state = meeting_agent.invoke(initial_state, {"recursion_limit": 15})

    final_decision = final_result_state.get("decision")
    final_answer = final_result_state.get("answer")

    if final_decision == 'helpful':
        logging.info("--- 최종 결정이 'helpful'이므로, 생성된 추천안을 반환합니다. ---")
        return {"final_answer": final_answer}
    else:
        logging.info("--- 최종 결정이 'helpful'이 아니므로, 신규 생성을 유도하기 위해 빈 추천안을 반환합니다. ---")
        empty_recommendation = json.dumps({"summary": "", "recommendations": []})
        return {"final_answer": empty_recommendation}


# --- 전문가 2: 취미 추천 에이전트 (StateGraph 기반으로 교체) ---

# [수정] 취미 추천 에이전트가 사용하는 헬퍼 함수
def normalize(value, min_val, max_val):
    """(기존 _normalize에서 이름 변경)"""
    if value is None: return None
    return round((value - min_val) / (max_val - min_val), 4)

def generate_prompt(profile):
    """[신규 추가] 사용자 프로필 딕셔너리를 받아 Gemini에게 보낼 자연어 프롬프트를 생성합니다."""
    
    def get_val(val, format_str="{:.2f}"):
        if val is None: return "N/A"
        if format_str: return format_str.format(val)
        return val

    # 프로필의 각 섹션을 안전하게 가져옵니다.
    fsc = profile.get('FSC', {})
    pssr = profile.get('PSSR', {})
    mp = profile.get('MP', {})
    dls = profile.get('DLS', {})
    ip = profile.get('IP', {}) # ★ 관심사 프로필(IP)을 가져옵니다.

    # --- 프로필 요약 ---
    fsc_summary = (f"* **현실적 제약**: 시간({get_val(fsc.get('time_availability'))}), 예산({get_val(fsc.get('financial_budget'))}), 에너지({get_val(fsc.get('energy_level'))}), 이동성({get_val(fsc.get('mobility'))}) / 선호공간: {get_val(fsc.get('preferred_space'), format_str=None)}")
    pssr_summary = (f"* **심리적 상태**: 사회적 불안({get_val(pssr.get('social_anxiety_score'))}), 현재 고립 수준({get_val(pssr.get('isolation_level'))}) (0:고립, 1:활발)")
    mp_summary = (f"* **주요 동기**: 핵심 목표는 '{get_val(mp.get('core_motivation'), format_str=None)}' 입니다.")
    dls_summary = (f"* **사회성 선호**: '{get_val(dls.get('preferred_sociality_type'), format_str=None)}' 활동을 선호합니다.")
    
    # --- ★★★ 이 부분이 추가되었습니다 ★★★ ---
    # 관심사 프로필 요약 생성
    ip_summary = (f"* **관심사 프로필** (0:관심없음, 1:매우관심): 자연({get_val(ip.get('nature_interest'))}), "
                  f"손으로 만들기({get_val(ip.get('craft_interest'))}), "
                  f"지적 탐구({get_val(ip.get('intellect_interest'))}), "
                  f"예술({get_val(ip.get('art_interest'))}), "
                  f"신체 활동({get_val(ip.get('activity_interest'))})")

    # 강력한 금지 규칙 생성
    hard_constraints = "\n# ★★★ 금지 규칙 (Hard Constraints) ★★★\n"
    hard_constraints += "아래 규칙은 사용자의 명시적인 의사이므로, 사진의 내용과 상충되더라도 반드시 지켜야 합니다:\n"
    
    if ip.get('nature_interest', 0.5) < 0.3: # 0.5는 기본값, 0.3은 '관심 없음' 기준
        hard_constraints += "- **금지**: '자연' 관련 활동 (예: 텃밭 가꾸기, 산책, 등산)은 사용자의 관심도가 매우 낮으므로 절대 추천하지 마세요.\n"
    if ip.get('craft_interest', 0.5) < 0.3:
        hard_constraints += "- **금지**: '손으로 만들기' 관련 활동 (예: 공예, 요리)은 절대 추천하지 마세요.\n"
    if ip.get('intellect_interest', 0.5) < 0.3:
        hard_constraints += "- **금지**: '지적 탐구' 관련 활동 (예: 독서, 공부)은 절대 추천하지 마세요.\n"
    if ip.get('art_interest', 0.5) < 0.3:
        hard_constraints += "- **금지**: '예술' 관련 활동 (예: 그림, 음악)은 절대 추천하지 마세요.\n"
    if ip.get('activity_interest', 0.5) < 0.3:
        hard_constraints += "- **금지**: '신체 활동' 관련 활동 (예: 운동, 춤)은 절대 추천하지 마세요.\n"
    # --- ★★★ 여기까지 ★★★ ---


    # 최종 프롬프트 조합
    prompt = f"""# 페르소나 (Persona)
당신은 사용자의 내면을 깊이 이해하고 공감하는 '디지털 치료 레크리에이션 전문가'입니다.

# 컨텍스트: 사용자 프로필 (Context: User Profile)
아래는 사용자의 현재 상태를 분석한 데이터입니다. 이 정보는 반드시 지켜야 할 가이드라인입니다.
{fsc_summary}
{pssr_summary}
{mp_summary}
{dls_summary}
{ip_summary} 

{hard_constraints}

# 이미지 분석 지시 (Image Analysis Directive)
이제 이 사용자가 '즐거웠던 순간'으로 직접 선택한 아래 사진들을 분석해 주세요. 사진 속의 명확한 사물이나 활동뿐만 아니라, 전체적인 분위기, 색감, 빛, 구도, 질감 등에서 느껴지는 감성적인 단서를 포착해 주세요.

# 핵심 과제 및 결과물 형식 (Core Task & Output Format)
위의 사용자 프로필과 이미지 분석을 종합하여, 사용자에게 맞춤형 취미 추천 메시지를 작성해주세요. 결과물은 아래 두 부분으로 구성되어야 하며, 반드시 지정된 형식을 준수해야 합니다.

### 1. 사용자 분석 요약
"안녕하세요, 사용자님의 내면 깊은 곳을 이해하고 공감하는 디지털 치료 레크리에이션 전문가입니다." 라는 문장으로 시작해주세요.
그 다음, [사용자 프로필]과 [이미지 분석] 결과를 종합하여 파악한 사용자의 성향, 잠재된 욕구, 선호도 등을 1~2 문단으로 요약하여 친절하게 설명해주세요.
예시: "사용자님의 소중한 '즐거웠던 순간' 사진과 현재 프로필을 면밀히 살펴보았습니다. 강한 에너지와 자기 계발 의지, 그리고 진정한 '연결'을 향한 갈망을 느낄 수 있었습니다..."

### 2. 맞춤 취미 제안 (3가지)
분석 요약에 이어서, 이 사용자가 지금 바로 시작할 수 있는 맞춤형 취미 3가지를 추천해주세요.
각 취미는 다음 형식을 반드시 준수하여 설명해야 합니다. **`###`나 `**` 같은 마크다운 문법은 절대 사용하지 마세요.**

---
[첫 번째 추천 취미]
여기에 취미의 이름을 적어주세요.

[추천 이유]
여기에 왜 이 취미가 사용자에게 적합한지, 프로필과 사진 분석 결과를 바탕으로 자연스럽게 설명해주세요. `자연(0.75)`와 같은 내부 분석 점수는 절대 노출하지 마세요.

[부드러운 첫걸음]
여기에 사용자가 부담 없이 시작할 수 있는 구체적인 첫 행동을 초대하는 말투로 제안해주세요.

---
(두 번째, 세 번째 취미도 위와 동일한 형식으로 반복)
"""
    print("Gemini 프롬프트 생성 완료.")
    return prompt

# 2-1. 취미 추천에 사용될 도구(Tool) 정의
@tool
def analyze_survey_tool(survey_json_string: str) -> dict:
    """[수정] 사용자의 설문 응답(JSON 문자열)을 입력받아, IP 프로필이 포함된 수치적 성향 프로필(딕셔너리)을 반환합니다."""
    logging.info("--- 📊 '설문 분석 전문가'가 작업을 시작합니다. (IP 프로필 포함) ---")
    try:
        responses = json.loads(survey_json_string)

        # --- 새로운 calculate_user_profile_normalized 로직 시작 ---
        
        # features 딕셔너리에 'IP' (Interest Profile) 키를 추가합니다.
        features = {'FSC': {}, 'PSSR': {}, 'MP': {}, 'DLS': {}, 'IP': {}}
        
        def to_int(q_num_str):
            return responses.get(q_num_str)

        # --- FSC (기존과 동일) ---
        features['FSC']['time_availability'] = normalize(to_int('1'), 1, 4)
        features['FSC']['financial_budget'] = normalize(to_int('2'), 1, 4)
        features['FSC']['energy_level'] = normalize(to_int('3'), 1, 5)
        features['FSC']['mobility'] = normalize(to_int('4'), 1, 5)
        features['FSC']['has_physical_constraints'] = True if to_int('5') in [1, 2, 3] else False
        features['FSC']['has_housing_constraints'] = True if to_int('12') in [2, 3, 4] else False
        features['FSC']['preferred_space'] = 'indoor' if to_int('6') == 1 else 'outdoor'

        # --- PSSR (기존과 동일) ---
        q13 = to_int('13') or 3; q14_r = 6 - (to_int('14') or 3); q16 = to_int('16') or 3
        self_criticism_raw = (q13 + q14_r + q16) / 3
        features['PSSR']['self_criticism_score'] = normalize(self_criticism_raw, 1, 5)
        q15 = to_int('15') or 3; q18 = to_int('18') or 3; q20 = to_int('20') or 3
        social_anxiety_raw = (q15 + q18 + q20) / 3
        features['PSSR']['social_anxiety_score'] = normalize(social_anxiety_raw, 1, 5)
        features['PSSR']['isolation_level'] = normalize(to_int('21'), 1, 5)
        features['PSSR']['structure_preference_score'] = normalize(to_int('27'), 1, 5)
        features['PSSR']['avoidant_coping_score'] = normalize(to_int('29'), 1, 5)

        # --- MP (한국어 값으로 업데이트) ---
        motivation_map = {1: '성취', 2: '회복', 3: '연결', 4: '활력'}
        features['MP']['core_motivation'] = motivation_map.get(to_int('31'))
        features['MP']['value_profile'] = {'knowledge': normalize(to_int('33'), 1, 5), 'stability': normalize(to_int('34'), 1, 5),'relationship': normalize(to_int('35'), 1, 5), 'health': normalize(to_int('36'), 1, 5),'creativity': normalize(to_int('37'), 1, 5), 'control': normalize(to_int('38'), 1, 5),}
        features['MP']['process_orientation_score'] = normalize(6 - (to_int('41') or 3), 1, 5)

        # --- DLS (한국어 값으로 업데이트) ---
        sociality_map = {1: '단독형', 2: '병렬형', 3: '저강도 상호작용형', 4: '고강도 상호작용형'}
        features['DLS']['preferred_sociality_type'] = sociality_map.get(to_int('39'))
        group_size_map = {1: '1:1', 2: '소규모 그룹', 3: '대규모 그룹'}
        features['DLS']['preferred_group_size'] = group_size_map.get(to_int('40'))
        features['DLS']['autonomy_preference_score'] = normalize(to_int('42'), 1, 5)
        
        # --- ★★★ 추가된 IP (Interest Profile) 로직 ★★★ ---
        features['IP']['nature_interest'] = normalize(to_int('43'), 1, 5)
        features['IP']['craft_interest'] = normalize(to_int('44'), 1, 5)
        features['IP']['intellect_interest'] = normalize(to_int('45'), 1, 5)
        features['IP']['art_interest'] = normalize(to_int('46'), 1, 5)
        features['IP']['activity_interest'] = normalize(to_int('47'), 1, 5)
        # --- ★★★ 여기까지 ★★★ ---
        
        logging.info("--- ✅ 설문 분석이 성공적으로 완료되었습니다. ---")
        return features
    except Exception as e:
        logging.error(f"설문 분석 중 오류 발생: {e}", exc_info=True)
        return {"error": f"설문 분석 중 오류가 발생했습니다: {e}"}

@tool
def analyze_photo_tool(image_paths: list[str], survey_profile: dict) -> str:
    """[수정] 사용자의 사진(image_paths)과 설문 프로필(survey_profile)을 모두 입력받아,
    두 정보를 종합하여 최종 취미 추천 메시지를 생성하고 반환합니다."""
    from PIL import Image
    
    # 1. 프로필을 기반으로 Gemini에게 보낼 프롬프트를 생성합니다.
    try:
        prompt_text = generate_prompt(survey_profile)
    except Exception as e:
        logging.error(f"프로필 기반 프롬프트 생성 중 오류 발생: {e}", exc_info=True)
        return f"오류: 사용자 프로필로 프롬프트를 생성하는 데 실패했습니다: {e}"

    # 2. 이미지가 있는지 확인합니다.
    if not image_paths:
        logging.info("--- 🖼️ 분석할 사진이 없어 사진 분석 단계를 건너뜁니다. ---")
        # 사진이 없어도, 설문 기반 추천은 가능하므로 프롬프트만 전달합니다.
        image_parts = ["\n# 추가 정보: 사용자가 제공한 사진이 없습니다."]
    else:
        logging.info(f"--- 📸 '디지털 치료 레크리에이션 전문가'가 작업을 시작합니다. (이미지 {len(image_paths)}개) ---")
        image_parts = []

    # 3. 이미지 파일을 처리합니다.
    try:
        for path in image_paths:
            try:
                img = Image.open(path)
                # Pillow가 지원하지만 Gemini가 지원하지 않는 형식을 대비해 format 확인
                if img.format.upper() == 'MPO':
                    # MPO 파일의 경우, 첫 번째 프레임(일반적으로 JPEG)을 사용
                    img.seek(0)
                    # MPO에서 추출한 이미지는 format 속성이 없을 수 있으므로, JPEG로 간주하고 추가
                    image_parts.append(img.copy()) # copy()로 안전하게 추가
                    logging.info(f"MPO 형식 파일에서 JPEG 프레임을 추출했습니다: {path}")
                elif img.format.upper() in ['JPEG', 'PNG', 'WEBP', 'HEIC', 'HEIF']:
                    image_parts.append(img)
                else:
                    logging.warning(f"지원하지 않는 이미지 형식({img.format})을 건너뜁니다: {path}")
            except Exception as img_e:
                logging.warning(f"이미지 파일을 여는 데 실패하여 건너뜁니다: {path}, 오류: {img_e}")
        
        # 4. Gemini 모델을 호출합니다.
        model = genai.GenerativeModel('gemini-2.5-flash')
        # [수정] generate_prompt로 생성한 프롬프트와 이미지 파트를 함께 전달
        response = model.generate_content([prompt_text] + image_parts) 
        
        logging.info("--- ✅ 최종 추천 메시지 생성이 성공적으로 완료되었습니다. ---")
        return response.text
    except Exception as e:
        logging.error(f"Gemini 추천 생성 중 오류 발생: {e}", exc_info=True)
        return f"오류: Gemini를 통한 최종 추천 생성 중 문제가 발생했습니다: {e}"

# 2-2. 취미 추천 StateGraph 정의 [수정]
class HobbyAgentState(TypedDict):
    survey_data: dict
    image_paths: List[str]
    survey_profile: dict
    # [삭제] survey_summary: str
    # [삭제] photo_analysis: str
    final_recommendation: str

def analyze_survey_node(state: HobbyAgentState):
    """설문 데이터를 분석하여 정량 프로필을 생성하는 노드"""
    survey_json_string = json.dumps(state["survey_data"], ensure_ascii=False)
    survey_profile = analyze_survey_tool.invoke({"survey_json_string": survey_json_string})
    return {"survey_profile": survey_profile}

def analyze_photo_node(state: HobbyAgentState):
    """[수정] 사진과 설문 프로필을 종합하여 최종 추천을 생성하는 노드"""
    final_recommendation = analyze_photo_tool.invoke({
        "image_paths": state.get("image_paths", []),
        "survey_profile": state["survey_profile"]
    })
    return {"final_recommendation": final_recommendation}

# 2-3. 취미 추천 StateGraph 컴파일 [수정]
hobby_graph_builder = StateGraph(HobbyAgentState)
hobby_graph_builder.add_node("analyze_survey", analyze_survey_node)
hobby_graph_builder.add_node("analyze_photo_and_recommend", analyze_photo_node) # [수정] 노드 이름 변경

hobby_graph_builder.set_entry_point("analyze_survey")
hobby_graph_builder.add_edge("analyze_survey", "analyze_photo_and_recommend") # [수정] 엣지 연결
hobby_graph_builder.add_edge("analyze_photo_and_recommend", END) # [수정] 엣지 연결

hobby_supervisor_agent = hobby_graph_builder.compile()

# 2-4. 마스터 에이전트가 호출할 함수
def call_multimodal_hobby_agent(state: MasterAgentState):
    """'StateGraph 기반 취미 추천 에이전트'를 호출하고 결과를 받아오는 노드"""
    logging.info("--- CALLING: StateGraph Hobby Supervisor Agent (IP Profile Ver.) ---")

    user_input = state["user_input"]
    survey_data = user_input.get("survey", {})
    image_paths = user_input.get("image_paths", [])

    input_data = {"survey_data": survey_data, "image_paths": image_paths}
    
    final_state = hobby_supervisor_agent.invoke(input_data, config={"recursion_limit": 10})
    
    final_answer = final_state.get("final_recommendation", "오류: 최종 추천을 생성하는 데 실패했습니다.")
                
    return {"final_answer": final_answer}


# 5-4. 마스터 에이전트 그래프 조립 및 컴파일
master_graph_builder = StateGraph(MasterAgentState)

master_graph_builder.add_node("router", route_request)
master_graph_builder.add_node("meeting_matcher", call_meeting_matching_agent)
master_graph_builder.add_node("hobby_recommender", call_multimodal_hobby_agent)
master_graph_builder.add_node("general_searcher", call_general_search_agent) # 새 노드 추가

master_graph_builder.set_entry_point("router")

master_graph_builder.add_conditional_edges(
    "router", 
    lambda state: state['route'],
    {
        "meeting_matching": "meeting_matcher", 
        "hobby_recommendation": "hobby_recommender",
        "general_search": "general_searcher" # 새 경로 연결
    }
)

master_graph_builder.add_edge("meeting_matcher", END)
master_graph_builder.add_edge("hobby_recommender", END)
master_graph_builder.add_edge("general_searcher", END) # 새 노드 종료점 연결

master_agent = master_graph_builder.compile()


# --- 6. API 엔드포인트 정의 ---
class UserRequest(BaseModel):
    user_input: dict

@app.post("/agent/invoke")
async def invoke_agent(request: UserRequest):
    try:
        input_data = {"user_input": request.user_input}
        result = master_agent.invoke(input_data, {"recursion_limit": 5}) # 마스터 에이전트에도 안전장치 추가
        return {"final_answer": result.get("final_answer", "오류: 최종 답변을 생성하지 못했습니다.")}
    except Exception as e:
        logging.error(f"Agent 실행 중 심각한 오류 발생: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"AI 에이전트 처리 중 내부 서버 오류가 발생했습니다: {str(e)}")

class NewMeeting(BaseModel):
    meeting_id: str
    title: str
    description: str
    time: str
    location: str

@app.post("/meetings/add")
async def add_meeting_to_pinecone(meeting: NewMeeting):
    try:
        logging.info(f"--- Pinecone에 새로운 모임 추가 시작 (ID: {meeting.meeting_id}) ---")
        meeting_index_name = os.getenv("PINECONE_INDEX_NAME_MEETING")
        if not meeting_index_name: raise ValueError("'.env' 파일에 PINECONE_INDEX_NAME_MEETING이(가) 설정되지 않았습니다.")
        
        embedding_function = OpenAIEmbeddings(model='text-embedding-3-large')
        vector_store = PineconeVectorStore.from_existing_index(index_name=meeting_index_name, embedding=embedding_function)
        
        full_text = f"제목: {meeting.title}\n설명: {meeting.description}\n시간: {meeting.time}\n장소: {meeting.location}"
        metadata = {
            "title": meeting.title, 
            "description": meeting.description, 
            "time": meeting.time, 
            "location": meeting.location,
            "meeting_id": meeting.meeting_id 
        }
        
        vector_store.add_texts(texts=[full_text], metadatas=[metadata], ids=[meeting.meeting_id])
        
        logging.info(f"--- Pinecone에 모임 추가 성공 (ID: {meeting.meeting_id}) ---")
        return {"status": "success", "message": f"모임(ID: {meeting.meeting_id})이 성공적으로 추가되었습니다."}
    except Exception as e:
        logging.error(f"Pinecone 업데이트 중 오류 발생: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Pinecone에 모임을 추가하는 중 오류가 발생했습니다: {str(e)}")

@app.delete("/meetings/delete/{meeting_id}")
async def delete_meeting_from_pinecone(meeting_id: str):
    try:
        logging.info(f"--- Pinecone에서 모임 삭제 시작 (ID: {meeting_id}) ---")
        meeting_index_name = os.getenv("PINECONE_INDEX_NAME_MEETING")
        if not meeting_index_name: raise ValueError("'.env' 파일에 PINECONE_INDEX_NAME_MEETING이(가) 설정되지 않았습니다.")
        
        embedding_function = OpenAIEmbeddings(model='text-embedding-3-large')
        vector_store = PineconeVectorStore.from_existing_index(index_name=meeting_index_name, embedding=embedding_function)
        
        vector_store.delete(ids=[meeting_id])
        
        logging.info(f"--- Pinecone에서 모임 삭제 성공 (ID: {meeting_id}) ---")
        return {"status": "success", "message": f"모임(ID: {meeting_id})이 성공적으로 삭제되었습니다."}
    except Exception as e:
        logging.error(f"Pinecone 삭제 중 오류 발생: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Pinecone에서 모임을 삭제하는 중 오류가 발생했습니다.: {str(e)}")
